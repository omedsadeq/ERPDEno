<?php
/**
 * Accountant Receiving Feed API
 * ئایپیئای وەرگرتنی نێردراوەکانی دکتۆر بۆ ژمێریار
 *
 * One unified, polling-friendly feed of EVERYTHING doctors send to the
 * accountant, built on the three existing sources (no new tables, so the
 * feed can never drift from the real queues):
 *   - accounting_reports              → کەیس / تێبینی (case report or follow-up note)
 *   - payments (status = pending)     → پارەدان (payment requests awaiting confirm)
 *   - xray_requests (at accountant)   → پشکنین / ئێکسڕەی (x-ray orders to approve)
 *
 * Actions:
 *   list    — merged feed, newest first. `since` (server_time from the previous
 *             poll) makes the response report how many rows are new, so the
 *             page can play a sound / flash a badge without a page refresh.
 *   detail  — full drill-down for the eye (👁) modal of one feed row.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}
if (!$auth->isAccountant() && !$auth->isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

$db = Database::getInstance();
$action = $_GET['action'] ?? 'list';

// ---------------------------------------------------------------- helpers

function feed_time_ago($datetime) {
    $now = new DateTime();
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);
    if ($diff->y > 0) return $diff->y . ' ساڵ پێش';
    if ($diff->m > 0) return $diff->m . ' مانگ پێش';
    if ($diff->d > 0) return $diff->d . ' ڕۆژ پێش';
    if ($diff->h > 0) return $diff->h . ' کاتژمێر پێش';
    if ($diff->i > 0) return $diff->i . ' خولەک پێش';
    return 'ئێستا';
}

function feed_get_str($key, $default = '') {
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function feed_valid_date($d) {
    return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
}

function feed_valid_datetime($d) {
    return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $d) && strtotime($d) !== false;
}

$kindLabels = [
    'report'  => 'کەیس / چارەسەر',
    'note'    => 'تێبینی',
    'payment' => 'پارەدان',
    'xray'    => 'پشکنین / ئێکسڕەی',
    'station' => 'نەخۆش لە وێستگە',
];

$stationStatusLabels = [
    'at_accounting' => 'لە وێستگەیە',
    'at_xray'       => 'لە ئێکسڕەیە',
    'in_progress'   => 'گەڕاوەتەوە بۆ دکتۆر',
    'in_treatment'  => 'گەڕاوەتەوە بۆ دکتۆر',
    'completed'     => 'تەواوبوو',
    'cancelled'     => 'هەڵوەشاوە',
    'no_show'       => 'نەهاتووە',
];

$reportStatusLabels = ['pending' => 'چاوەڕوان', 'reviewed' => 'بینراوە', 'processed' => 'جێبەجێکراوە'];
$paymentStatusLabels = ['pending' => 'چاوەڕوان', 'approved' => 'پەسەندکراوە', 'rejected' => 'ڕەتکراوەتەوە', 'refunded' => 'گەڕاوەتەوە'];
$xrayStatusLabels = [
    'pending_accountant'     => 'چاوەڕوانی ژمێریار',
    'returned_to_accountant' => 'گەڕاوەتەوە بۆ ژمێریار',
    'pending_xray'           => 'چاوەڕوانی ئێکسڕەی',
    'in_xray'                => 'لە ئێکسڕەی',
    'completed'              => 'تەواوبوو',
    'cancelled'              => 'هەڵوەشاوە',
];
$xrayTypeLabels = [
    'panoramic'     => 'پانۆراما',
    'cbct'          => 'CBCT',
    'periapical'    => 'پێریئەپیکاڵ',
    'cephalometric' => 'سیفالۆمەتریک',
];
$paymentTypeLabels = ['cash' => 'کاش', 'credit' => 'قەرز', 'installment' => 'قیست'];
$paymentMethodLabels = ['cash' => 'کاش', 'card' => 'کارت', 'transfer' => 'گواستنەوە', 'other' => 'هیتر'];

try {
    if ($action === 'list') {
        // ------------------------------------------------------------ filters
        $kind     = feed_get_str('kind', 'all');            // all | report | payment | xray
        $scope    = feed_get_str('scope', 'open');          // open | all
        $search   = feed_get_str('search');
        $dateFrom = feed_get_str('date_from');
        $dateTo   = feed_get_str('date_to');
        $since    = feed_get_str('since');                  // poll cursor (server_time of last poll)
        if (!in_array($kind, ['all', 'report', 'payment', 'xray', 'station'], true)) { $kind = 'all'; }
        if (!in_array($scope, ['open', 'all'], true)) { $scope = 'open'; }
        if ($dateFrom !== '' && !feed_valid_date($dateFrom)) { $dateFrom = ''; }
        if ($dateTo !== '' && !feed_valid_date($dateTo)) { $dateTo = ''; }
        if ($since !== '' && !feed_valid_datetime($since)) { $since = ''; }

        // "all" without a date filter is bounded to the last 30 days so the
        // history view can't pull the whole table through the poller.
        if ($scope === 'all' && $dateFrom === '' && $dateTo === '') {
            $dateFrom = date('Y-m-d', strtotime('-30 days'));
        }

        $items = [];

        // ------------------------------------------- 1) case reports / notes
        if ($kind === 'all' || $kind === 'report') {
            $where = $scope === 'open' ? "ar.status IN ('pending','reviewed')" : "1=1";
            $params = [];
            if ($search !== '') {
                // Distinct placeholder names — PDO with emulation off rejects
                // re-using one named placeholder (fatal on every search)
                $where .= " AND (p.full_name LIKE :s1 OR p.patient_code LIKE :s2 OR c.case_number LIKE :s3 OR u.full_name LIKE :s4)";
                $params['s1'] = $params['s2'] = $params['s3'] = $params['s4'] = "%{$search}%";
            }
            if ($dateFrom !== '') { $where .= " AND DATE(ar.sent_at) >= :df"; $params['df'] = $dateFrom; }
            if ($dateTo !== '')   { $where .= " AND DATE(ar.sent_at) <= :dt"; $params['dt'] = $dateTo; }

            $rows = $db->fetchAll("
                SELECT ar.id, ar.case_id, ar.status, ar.notes, ar.sent_at,
                       c.case_number, c.case_name, c.total_amount, c.paid_amount, c.discount_amount,
                       p.id AS patient_id, p.full_name AS patient_name, p.patient_code, p.phone AS patient_phone,
                       u.full_name AS doctor_name
                FROM accounting_reports ar
                JOIN cases c ON ar.case_id = c.id
                JOIN patient_folders pf ON c.folder_id = pf.id
                JOIN patients p ON pf.patient_id = p.id
                JOIN users u ON ar.sent_by = u.id
                WHERE {$where}
                ORDER BY ar.sent_at DESC
                LIMIT 150
            ", $params);

            foreach ($rows as $r) {
                $remaining = max(0, (float)$r['total_amount'] - (float)$r['paid_amount'] - (float)$r['discount_amount']);
                // A send that carries only text (no open balance) reads as a
                // plain note; one with money to collect reads as a case report.
                $rowKind = ($remaining <= 0 && trim((string)$r['notes']) !== '') ? 'note' : 'report';
                $items[] = [
                    'uid'          => 'report-' . $r['id'],
                    'kind'         => $rowKind,
                    'kind_label'   => $kindLabels[$rowKind],
                    'purpose'      => $rowKind === 'note'
                        ? 'تێبینی / ڕاسپاردە بۆ ژمێریار'
                        : 'وەرگرتنی پارەی کەیسی تەواوبوو',
                    'id'           => (int)$r['id'],
                    'case_id'      => (int)$r['case_id'],
                    'case_number'  => $r['case_number'],
                    'patient_id'   => (int)$r['patient_id'],
                    'patient_name' => $r['patient_name'],
                    'patient_code' => $r['patient_code'],
                    'doctor_name'  => $r['doctor_name'],
                    'amount'       => $remaining > 0 ? $remaining : null,
                    'note'         => (string)$r['notes'],
                    'status'       => $r['status'],
                    'status_label' => $reportStatusLabels[$r['status']] ?? $r['status'],
                    'is_open'      => in_array($r['status'], ['pending', 'reviewed'], true) ? 1 : 0,
                    'sent_at'      => $r['sent_at'],
                    'time_ago'     => feed_time_ago($r['sent_at']),
                    'page_url'     => APP_URL . '/accountant/report-detail.php?case_id=' . (int)$r['case_id'],
                ];
            }
        }

        // ------------------------------------------------ 2) payment requests
        if ($kind === 'all' || $kind === 'payment') {
            $where = $scope === 'open' ? "pay.status = 'pending'" : "1=1";
            $params = [];
            if ($search !== '') {
                $where .= " AND (p.full_name LIKE :s1 OR p.patient_code LIKE :s2 OR c.case_number LIKE :s3 OR doc.full_name LIKE :s4)";
                $params['s1'] = $params['s2'] = $params['s3'] = $params['s4'] = "%{$search}%";
            }
            if ($dateFrom !== '') { $where .= " AND DATE(pay.payment_date) >= :df"; $params['df'] = $dateFrom; }
            if ($dateTo !== '')   { $where .= " AND DATE(pay.payment_date) <= :dt"; $params['dt'] = $dateTo; }

            $rows = $db->fetchAll("
                SELECT pay.id, pay.case_id, pay.payment_number, pay.amount, pay.payment_type,
                       pay.payment_method, pay.status, pay.notes, pay.payment_date,
                       c.case_number,
                       p.id AS patient_id, p.full_name AS patient_name, p.patient_code, p.phone AS patient_phone,
                       doc.full_name AS doctor_name
                FROM payments pay
                JOIN cases c ON pay.case_id = c.id
                JOIN patient_folders pf ON c.folder_id = pf.id
                JOIN patients p ON pf.patient_id = p.id
                LEFT JOIN users doc ON pay.received_by = doc.id
                WHERE {$where}
                ORDER BY pay.payment_date DESC
                LIMIT 150
            ", $params);

            foreach ($rows as $r) {
                $items[] = [
                    'uid'          => 'payment-' . $r['id'],
                    'kind'         => 'payment',
                    'kind_label'   => $kindLabels['payment'],
                    'purpose'      => 'پەسەندکردنی پارەدان — ' . ($paymentTypeLabels[$r['payment_type']] ?? $r['payment_type']),
                    'id'           => (int)$r['id'],
                    'case_id'      => (int)$r['case_id'],
                    'case_number'  => $r['case_number'],
                    'patient_id'   => (int)$r['patient_id'],
                    'patient_name' => $r['patient_name'],
                    'patient_code' => $r['patient_code'],
                    'doctor_name'  => $r['doctor_name'],
                    'amount'       => (float)$r['amount'],
                    'note'         => (string)$r['notes'],
                    'status'       => $r['status'],
                    'status_label' => $paymentStatusLabels[$r['status']] ?? $r['status'],
                    'is_open'      => $r['status'] === 'pending' ? 1 : 0,
                    'sent_at'      => $r['payment_date'],
                    'time_ago'     => feed_time_ago($r['payment_date']),
                    'page_url'     => APP_URL . '/accountant/report-detail.php?case_id=' . (int)$r['case_id'],
                ];
            }
        }

        // -------------------------------------------------- 3) x-ray requests
        if ($kind === 'all' || $kind === 'xray') {
            $where = $scope === 'open'
                ? "xr.request_status IN ('pending_accountant','returned_to_accountant')"
                : "1=1";
            $params = [];
            if ($search !== '') {
                $where .= " AND (p.full_name LIKE :s1 OR p.patient_code LIKE :s2 OR xr.request_number LIKE :s3 OR u.full_name LIKE :s4)";
                $params['s1'] = $params['s2'] = $params['s3'] = $params['s4'] = "%{$search}%";
            }
            if ($dateFrom !== '') { $where .= " AND DATE(xr.created_at) >= :df"; $params['df'] = $dateFrom; }
            if ($dateTo !== '')   { $where .= " AND DATE(xr.created_at) <= :dt"; $params['dt'] = $dateTo; }

            $rows = $db->fetchAll("
                SELECT xr.id, xr.case_id, xr.request_number, xr.xray_type, xr.reason_details,
                       xr.payment_type, xr.amount, xr.discount_amount, xr.final_amount,
                       xr.request_status, xr.notes, xr.created_at,
                       p.id AS patient_id, p.full_name AS patient_name, p.patient_code, p.phone AS patient_phone,
                       u.full_name AS doctor_name
                FROM xray_requests xr
                JOIN patients p ON xr.patient_id = p.id
                LEFT JOIN users u ON xr.requested_by = u.id
                WHERE {$where}
                ORDER BY xr.created_at DESC
                LIMIT 150
            ", $params);

            foreach ($rows as $r) {
                $typeLabel = $xrayTypeLabels[$r['xray_type']] ?? $r['xray_type'];
                $note = trim(($typeLabel ? $typeLabel . ' — ' : '') . (string)($r['notes'] !== '' && $r['notes'] !== null ? $r['notes'] : $r['reason_details']));
                $items[] = [
                    'uid'          => 'xray-' . $r['id'],
                    'kind'         => 'xray',
                    'kind_label'   => $kindLabels['xray'],
                    'purpose'      => 'پەسەندکردنی پارەی ئێکسڕەی — ' . $typeLabel,
                    'id'           => (int)$r['id'],
                    'case_id'      => $r['case_id'] ? (int)$r['case_id'] : null,
                    'case_number'  => $r['request_number'],
                    'patient_id'   => (int)$r['patient_id'],
                    'patient_name' => $r['patient_name'],
                    'patient_code' => $r['patient_code'],
                    'doctor_name'  => $r['doctor_name'],
                    'amount'       => (float)$r['amount'],
                    'payment_type' => $r['payment_type'],
                    'discount'     => (float)($r['discount_amount'] ?? 0),
                    'final_amount' => (float)($r['final_amount'] ?: $r['amount']),
                    'note'         => $note,
                    'status'       => $r['request_status'],
                    'status_label' => $xrayStatusLabels[$r['request_status']] ?? $r['request_status'],
                    'is_open'      => in_array($r['request_status'], ['pending_accountant', 'returned_to_accountant'], true) ? 1 : 0,
                    'sent_at'      => $r['created_at'],
                    'time_ago'     => feed_time_ago($r['created_at']),
                    'page_url'     => APP_URL . '/accountant/pending-reports.php?tab=xray',
                ];
            }
        }

        // -------------------------- 4) patients sent to the accounting station
        // The waiting room's "بۆ ژمێریاری" button only flips waiting_room.status
        // to at_accounting (+ a visit_flow_log row) — no report/payment/x-ray
        // record exists, so without this source those sends never reach the
        // feed. Rows whose patient ALREADY has an open case report or x-ray
        // request are skipped: that flow shows its own (richer) row.
        if ($kind === 'all' || $kind === 'station') {
            $dedupe = "
                AND NOT EXISTS (
                    SELECT 1 FROM accounting_reports ar2
                    JOIN cases c2 ON ar2.case_id = c2.id
                    JOIN patient_folders pf2 ON c2.folder_id = pf2.id
                    WHERE pf2.patient_id = wr.patient_id
                      AND ar2.status IN ('pending','reviewed')
                )
                AND NOT EXISTS (
                    SELECT 1 FROM xray_requests xr2
                    WHERE xr2.patient_id = wr.patient_id
                      AND xr2.request_status IN ('pending_accountant','returned_to_accountant')
                )";
            $where = $scope === 'open'
                ? "wr.status = 'at_accounting' AND wr.check_in_time >= CURDATE()" . $dedupe
                : "1=1";
            $params = [];
            if ($search !== '') {
                $where .= " AND (p.full_name LIKE :s1 OR p.patient_code LIKE :s2 OR du.full_name LIKE :s3 OR mu.full_name LIKE :s4)";
                $params['s1'] = $params['s2'] = $params['s3'] = $params['s4'] = "%{$search}%";
            }
            if ($dateFrom !== '') { $where .= " AND DATE(COALESCE(vfl.created_at, wr.check_in_time)) >= :df"; $params['df'] = $dateFrom; }
            if ($dateTo !== '')   { $where .= " AND DATE(COALESCE(vfl.created_at, wr.check_in_time)) <= :dt"; $params['dt'] = $dateTo; }
            if ($scope === 'all') {
                // History = every handoff to the station, whatever happened next
                $where .= " AND vfl.id IS NOT NULL";
            }

            $rows = $db->fetchAll("
                SELECT wr.id, wr.patient_id, wr.status AS wr_status, wr.check_in_time,
                       wr.notes AS wr_notes,
                       COALESCE(vfl.created_at, wr.check_in_time) AS moved_at,
                       vfl.note AS flow_note,
                       p.full_name AS patient_name, p.patient_code, p.phone AS patient_phone,
                       du.full_name AS doctor_name, mu.full_name AS sender_name
                FROM waiting_room wr
                JOIN patients p ON wr.patient_id = p.id
                LEFT JOIN users du ON wr.doctor_id = du.id
                LEFT JOIN visit_flow_log vfl ON vfl.id = (
                    SELECT MAX(v2.id) FROM visit_flow_log v2
                    WHERE v2.waiting_room_id = wr.id AND v2.to_status = 'at_accounting'
                )
                LEFT JOIN users mu ON vfl.moved_by = mu.id
                WHERE {$where}
                ORDER BY moved_at DESC
                LIMIT 150
            ", $params);

            foreach ($rows as $r) {
                $note = trim((string)($r['flow_note'] !== null && $r['flow_note'] !== '' ? $r['flow_note'] : $r['wr_notes']));
                $items[] = [
                    'uid'          => 'station-' . $r['id'],
                    'kind'         => 'station',
                    'kind_label'   => $kindLabels['station'],
                    'purpose'      => 'نەخۆش نێردراوە بۆ وێستگەی ژمێریاری'
                                      . ($r['sender_name'] ? ' — لەلایەن ' . $r['sender_name'] : ''),
                    'id'           => (int)$r['id'],
                    'case_id'      => null,
                    'case_number'  => null,
                    'patient_id'   => (int)$r['patient_id'],
                    'patient_name' => $r['patient_name'],
                    'patient_code' => $r['patient_code'],
                    'doctor_name'  => $r['doctor_name'],
                    'amount'       => null,
                    'note'         => $note,
                    'status'       => $r['wr_status'],
                    'status_label' => $stationStatusLabels[$r['wr_status']] ?? $r['wr_status'],
                    'is_open'      => $r['wr_status'] === 'at_accounting' ? 1 : 0,
                    'sent_at'      => $r['moved_at'],
                    'time_ago'     => feed_time_ago($r['moved_at']),
                    'page_url'     => APP_URL . '/accountant/station.php',
                ];
            }
        }

        // Newest first across all sources
        usort($items, function ($a, $b) {
            return strcmp($b['sent_at'], $a['sent_at']);
        });
        $items = array_slice($items, 0, 200);

        // Open (actionable) counts per kind — for the header tiles/badges.
        // Counted over the full open queues, independent of current filters.
        $counts = [
            'report'  => (int)($db->fetch("SELECT COUNT(*) c FROM accounting_reports WHERE status IN ('pending','reviewed')")['c'] ?? 0),
            'payment' => (int)($db->fetch("SELECT COUNT(*) c FROM payments WHERE status = 'pending'")['c'] ?? 0),
            'xray'    => (int)($db->fetch("SELECT COUNT(*) c FROM xray_requests WHERE request_status IN ('pending_accountant','returned_to_accountant')")['c'] ?? 0),
            // Same dedupe as the list: only station patients with no open
            // report/x-ray of their own, so total never double-counts.
            'station' => (int)($db->fetch("
                SELECT COUNT(*) c FROM waiting_room wr
                WHERE wr.status = 'at_accounting' AND wr.check_in_time >= CURDATE()
                  AND NOT EXISTS (
                      SELECT 1 FROM accounting_reports ar2
                      JOIN cases c2 ON ar2.case_id = c2.id
                      JOIN patient_folders pf2 ON c2.folder_id = pf2.id
                      WHERE pf2.patient_id = wr.patient_id
                        AND ar2.status IN ('pending','reviewed')
                  )
                  AND NOT EXISTS (
                      SELECT 1 FROM xray_requests xr2
                      WHERE xr2.patient_id = wr.patient_id
                        AND xr2.request_status IN ('pending_accountant','returned_to_accountant')
                  )")['c'] ?? 0),
        ];
        $counts['total'] = $counts['report'] + $counts['payment'] + $counts['xray'] + $counts['station'];

        // How many of the returned rows arrived after the client's last poll
        $newCount = 0;
        $newUids = [];
        if ($since !== '') {
            foreach ($items as $it) {
                if ($it['sent_at'] > $since) {
                    $newCount++;
                    $newUids[] = $it['uid'];
                }
            }
        }

        echo json_encode([
            'success'     => true,
            'items'       => $items,
            'counts'      => $counts,
            'new_count'   => $newCount,
            'new_uids'    => $newUids,
            'server_time' => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
        exit;
    }

    // Accountant opened a report's eye → pending becomes 'reviewed' (بینراوە),
    // so the row leaves the چاوەڕوان bucket. Reports only: payment / x-ray
    // rows keep their own workflow statuses until they are actually settled.
    if ($action === 'mark_seen') {
        Csrf::require();
        $mKind = $_POST['kind'] ?? '';
        $mId = intval($_POST['id'] ?? 0);
        if ($mId <= 0 || !in_array($mKind, ['report', 'note'], true)) {
            echo json_encode(['success' => false, 'error' => 'Invalid request']);
            exit;
        }
        // Only from 'pending' — never downgrade a processed report
        $db->update('accounting_reports', ['status' => 'reviewed'],
            "id = :id AND status = 'pending'", ['id' => $mId]);
        $row = $db->fetch("SELECT status FROM accounting_reports WHERE id = :id", ['id' => $mId]);
        echo json_encode([
            'success'      => true,
            'status'       => $row['status'] ?? null,
            'status_label' => $reportStatusLabels[$row['status'] ?? ''] ?? ($row['status'] ?? ''),
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
        exit;
    }

    if ($action === 'xray_settle') {
        // Collect payment / approve debt for an x-ray and send it on to x-ray —
        // the same logic as pending-reports.php, but returned as JSON so the
        // receiving inbox settles it in place without leaving the page.
        Csrf::require();
        require_once __DIR__ . '/../classes/XrayManager.php';
        $xrayManager = new XrayManager();
        $currentUser = $auth->getCurrentUser();
        $role  = $_SESSION['role'] ?? ROLE_ACCOUNTANT;
        $xrayId = intval($_POST['xray_id'] ?? 0);
        $act   = $_POST['accountant_action'] ?? '';
        $note  = trim($_POST['action_note'] ?? '');

        $request = $xrayId > 0
            ? $db->fetch("SELECT id, request_status, amount FROM xray_requests WHERE id = :id", ['id' => $xrayId])
            : null;
        if (!$request || !in_array($request['request_status'], ['pending_accountant', 'returned_to_accountant'], true)) {
            echo json_encode(['success' => false, 'error' => 'داواکارییەکە نەدۆزرایەوە یان پێشتر جێبەجێکراوە'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (!in_array($act, ['approve_payment', 'approve_debt'], true)) {
            echo json_encode(['success' => false, 'error' => 'کردارێکی نادروست'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $failed = false;
        $result = ['success' => false];

        // Optional base-amount correction
        $newAmountRaw = trim($_POST['new_amount'] ?? '');
        if ($newAmountRaw !== '') {
            $newAmount = filter_var($newAmountRaw, FILTER_VALIDATE_FLOAT);
            if ($newAmount === false || $newAmount < 0) {
                echo json_encode(['success' => false, 'error' => 'بڕی نادروست'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            if (abs($newAmount - (float)$request['amount']) > 0.0001) {
                $result = $xrayManager->correctAmount($xrayId, $newAmount, $currentUser['id'], $role, $note);
                $failed = empty($result['success']);
            }
        }

        // Optional discount
        $discountRaw = trim($_POST['discount_amount'] ?? '');
        if (!$failed && $discountRaw !== '') {
            $discountAmount = filter_var($discountRaw, FILTER_VALIDATE_FLOAT);
            if ($discountAmount === false || $discountAmount < 0) {
                echo json_encode(['success' => false, 'error' => 'داشکاندنی نادروست'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            if ($discountAmount > 0) {
                $discountReason = trim($_POST['discount_reason'] ?? '');
                $result = $xrayManager->applyDiscount($xrayId, $discountAmount, $discountReason, $currentUser['id']);
                $failed = empty($result['success']);
            }
        }

        if (!$failed) {
            $result = $act === 'approve_payment'
                ? $xrayManager->approvePaymentAndSendToXray($xrayId, $currentUser['id'], $role, $note)
                : $xrayManager->approveDebtAndSendToXray($xrayId, $currentUser['id'], $role, $note);
        }

        echo json_encode([
            'success' => !empty($result['success']),
            'error'   => empty($result['success']) ? ($result['message'] ?? 'کردارەکە جێبەجێ نەکرا') : null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'hide') {
        // Archive (hide) a report so it drops out of the feed. Reversible from
        // the pending-reports "لابراوەکان" tab / report-detail restore button.
        Csrf::require();
        $mKind = $_POST['kind'] ?? '';
        $mId = intval($_POST['id'] ?? 0);
        if ($mId <= 0 || !in_array($mKind, ['report', 'note'], true)) {
            echo json_encode(['success' => false, 'error' => 'Invalid request']);
            exit;
        }
        $db->update('accounting_reports', ['status' => 'archived'], "id = :id", ['id' => $mId]);
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'detail') {
        $dKind = feed_get_str('kind');
        $id = intval($_GET['id'] ?? 0);
        if ($id <= 0 || !in_array($dKind, ['report', 'note', 'payment', 'xray', 'station'], true)) {
            echo json_encode(['success' => false, 'error' => 'Invalid request']);
            exit;
        }

        // ---------------------------------------------------- station detail
        if ($dKind === 'station') {
            $wr = $db->fetch("
                SELECT wr.*, p.full_name AS patient_name, p.patient_code, p.phone AS patient_phone,
                       du.full_name AS doctor_name,
                       vfl.created_at AS moved_at, vfl.note AS flow_note, mu.full_name AS sender_name
                FROM waiting_room wr
                JOIN patients p ON wr.patient_id = p.id
                LEFT JOIN users du ON wr.doctor_id = du.id
                LEFT JOIN visit_flow_log vfl ON vfl.id = (
                    SELECT MAX(v2.id) FROM visit_flow_log v2
                    WHERE v2.waiting_room_id = wr.id AND v2.to_status = 'at_accounting'
                )
                LEFT JOIN users mu ON vfl.moved_by = mu.id
                WHERE wr.id = :id
            ", ['id' => $id]);
            if (!$wr) {
                echo json_encode(['success' => false, 'error' => 'Not found']);
                exit;
            }
            // Outstanding balance across ALL of the patient's cases — the
            // number the cashier actually needs when the patient walks up.
            $debtRow = $db->fetch("
                SELECT COALESCE(SUM(GREATEST(c.total_amount - c.paid_amount - c.discount_amount, 0)), 0) AS debt
                FROM cases c
                JOIN patient_folders pf ON c.folder_id = pf.id
                WHERE pf.patient_id = :pid
            ", ['pid' => (int)$wr['patient_id']]);
            $note = trim((string)($wr['flow_note'] !== null && $wr['flow_note'] !== '' ? $wr['flow_note'] : $wr['notes']));
            echo json_encode([
                'success' => true,
                'kind'    => 'station',
                'header'  => [
                    'title'        => 'نەخۆش لە وێستگەی ژمێریاری',
                    'patient_name' => $wr['patient_name'],
                    'patient_code' => $wr['patient_code'],
                    'patient_phone'=> $wr['patient_phone'],
                    'doctor_name'  => $wr['doctor_name'],
                    'sent_at'      => $wr['moved_at'] ?: $wr['check_in_time'],
                    'status_label' => $stationStatusLabels[$wr['status']] ?? $wr['status'],
                ],
                'rows' => array_values(array_filter([
                    ['label' => 'کاتی هاتن (چێک ئین)', 'value' => $wr['check_in_time']],
                    ['label' => 'کاتی ناردن بۆ ژمێریاری', 'value' => $wr['moved_at'] ?: null],
                    ['label' => 'نێردراوە لەلایەن', 'value' => $wr['sender_name'] ?: null],
                    ['label' => 'قەرزی گشتی نەخۆش', 'value' => number_format((float)($debtRow['debt'] ?? 0))],
                    ['label' => 'تێبینی', 'value' => $note !== '' ? $note : null],
                ], function ($r) { return $r['value'] !== null && $r['value'] !== ''; })),
                'notes_history' => [],
                'services'      => [],
                'payments'      => [],
                'financial'     => null,
                'page_url'      => APP_URL . '/accountant/station.php',
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
            exit;
        }

        // ------------------------------------------------------ x-ray detail
        if ($dKind === 'xray') {
            $xr = $db->fetch("
                SELECT xr.*, p.full_name AS patient_name, p.patient_code, p.phone AS patient_phone,
                       u.full_name AS doctor_name
                FROM xray_requests xr
                JOIN patients p ON xr.patient_id = p.id
                LEFT JOIN users u ON xr.requested_by = u.id
                WHERE xr.id = :id
            ", ['id' => $id]);
            if (!$xr) {
                echo json_encode(['success' => false, 'error' => 'Not found']);
                exit;
            }
            echo json_encode([
                'success' => true,
                'kind'    => 'xray',
                'header'  => [
                    'title'        => 'داواکاری ئێکسڕەی — ' . $xr['request_number'],
                    'patient_name' => $xr['patient_name'],
                    'patient_code' => $xr['patient_code'],
                    'patient_phone'=> $xr['patient_phone'],
                    'doctor_name'  => $xr['doctor_name'],
                    'sent_at'      => $xr['created_at'],
                    'status_label' => $xrayStatusLabels[$xr['request_status']] ?? $xr['request_status'],
                ],
                'rows' => array_values(array_filter([
                    ['label' => 'جۆری ئێکسڕەی', 'value' => $xrayTypeLabels[$xr['xray_type']] ?? $xr['xray_type']],
                    ['label' => 'هۆکار', 'value' => trim((string)$xr['reason_details']) ?: null],
                    ['label' => 'جۆری پارەدان', 'value' => ['cash' => 'کاش', 'dont_cash' => 'کاش نییە', 'non_cash' => 'کاش نییە', 'debt' => 'قەرز'][$xr['payment_type']] ?? $xr['payment_type']],
                    ['label' => 'بڕ', 'value' => number_format((float)$xr['amount'])],
                    ['label' => 'تێبینی دکتۆر', 'value' => trim((string)$xr['notes']) ?: null],
                ], function ($r) { return $r['value'] !== null && $r['value'] !== ''; })),
                'notes_history' => [],
                'services'      => [],
                'payments'      => [],
                'financial'     => null,
                'page_url'      => APP_URL . '/accountant/pending-reports.php?tab=xray',
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
            exit;
        }

        // --------------------------------------- report / note / payment detail
        // All three are case-centric: resolve the case, then attach everything
        // the doctor has sent/recorded on it.
        if ($dKind === 'payment') {
            $pay = $db->fetch("SELECT * FROM payments WHERE id = :id", ['id' => $id]);
            if (!$pay) {
                echo json_encode(['success' => false, 'error' => 'Not found']);
                exit;
            }
            $caseId = (int)$pay['case_id'];
        } else {
            $report = $db->fetch("SELECT * FROM accounting_reports WHERE id = :id", ['id' => $id]);
            if (!$report) {
                echo json_encode(['success' => false, 'error' => 'Not found']);
                exit;
            }
            $caseId = (int)$report['case_id'];
        }

        $case = $db->fetch("
            SELECT c.*, p.id AS patient_id, p.full_name AS patient_name, p.patient_code, p.phone AS patient_phone,
                   d.name AS doctor_name
            FROM cases c
            JOIN patient_folders pf ON c.folder_id = pf.id
            JOIN patients p ON pf.patient_id = p.id
            LEFT JOIN doctors d ON pf.doctor_id = d.id
            WHERE c.id = :id
        ", ['id' => $caseId]);
        if (!$case) {
            echo json_encode(['success' => false, 'error' => 'Not found']);
            exit;
        }

        // Every note the doctor ever sent on this case, newest first
        $notesHistory = $db->fetchAll("
            SELECT ar.notes, ar.status, ar.sent_at, u.full_name AS doctor_name
            FROM accounting_reports ar
            JOIN users u ON ar.sent_by = u.id
            WHERE ar.case_id = :cid
            ORDER BY ar.sent_at DESC
            LIMIT 20
        ", ['cid' => $caseId]);
        foreach ($notesHistory as &$nh) {
            $nh['status_label'] = $reportStatusLabels[$nh['status']] ?? $nh['status'];
            $nh['time_ago'] = feed_time_ago($nh['sent_at']);
        }
        unset($nh);

        // Services/treatments the doctor performed (both recording systems)
        $services = [];
        foreach ($db->fetchAll("
            SELECT treatment_name AS name, tooth_number, quantity, final_price AS price,
                   notes, performed_date AS done_at
            FROM case_treatments
            WHERE case_id = :cid AND status != 'cancelled'
            ORDER BY created_at DESC
            LIMIT 50
        ", ['cid' => $caseId]) as $t) {
            $services[] = [
                'name'    => $t['name'] . ($t['tooth_number'] ? ' (ددان ' . $t['tooth_number'] . ')' : ''),
                'qty'     => (int)$t['quantity'],
                'price'   => (float)$t['price'],
                'notes'   => (string)$t['notes'],
                'done_at' => $t['done_at'],
            ];
        }
        if ($db->tableExists('arch_procedure_logs')) {
            foreach ($db->fetchAll("
                SELECT COALESCE(apl.custom_procedure_name, apt.type_name) AS name,
                       apl.price, apl.notes, apl.procedure_date AS done_at
                FROM arch_procedure_logs apl
                LEFT JOIN arch_procedure_types apt ON apl.procedure_type_id = apt.id
                WHERE apl.case_id = :cid
                ORDER BY apl.procedure_date DESC, apl.id DESC
                LIMIT 50
            ", ['cid' => $caseId]) as $t) {
                $services[] = [
                    'name'    => (string)$t['name'],
                    'qty'     => 1,
                    'price'   => (float)$t['price'],
                    'notes'   => (string)$t['notes'],
                    'done_at' => $t['done_at'],
                ];
            }
        }

        // Payment trail on the case (pending requests + confirmed money)
        $payments = $db->fetchAll("
            SELECT pay.payment_number, pay.amount, pay.payment_type, pay.payment_method,
                   pay.status, pay.notes, pay.payment_date, u.full_name AS received_by_name
            FROM payments pay
            LEFT JOIN users u ON pay.received_by = u.id
            WHERE pay.case_id = :cid
            ORDER BY pay.payment_date DESC
            LIMIT 30
        ", ['cid' => $caseId]);
        foreach ($payments as &$pm) {
            $pm['amount'] = (float)$pm['amount'];
            $pm['status_label'] = $paymentStatusLabels[$pm['status']] ?? $pm['status'];
            $pm['type_label'] = $paymentTypeLabels[$pm['payment_type']] ?? $pm['payment_type'];
            $pm['method_label'] = $paymentMethodLabels[$pm['payment_method']] ?? $pm['payment_method'];
        }
        unset($pm);

        $total = (float)$case['total_amount'];
        $paid = (float)$case['paid_amount'];
        $discount = (float)$case['discount_amount'];

        if ($dKind === 'payment') {
            $title = 'داواکاری پارەدان — ' . $pay['payment_number'];
            $sentAt = $pay['payment_date'];
            $statusLabel = $paymentStatusLabels[$pay['status']] ?? $pay['status'];
        } else {
            $title = 'ڕاپۆرتی کەیس — ' . $case['case_number'];
            $sentAt = $report['sent_at'];
            $statusLabel = $reportStatusLabels[$report['status']] ?? $report['status'];
        }

        echo json_encode([
            'success' => true,
            'kind'    => $dKind,
            'header'  => [
                'title'        => $title,
                'patient_name' => $case['patient_name'],
                'patient_code' => $case['patient_code'],
                'patient_phone'=> $case['patient_phone'],
                'doctor_name'  => $case['doctor_name'],
                'sent_at'      => $sentAt,
                'status_label' => $statusLabel,
            ],
            'rows' => array_values(array_filter([
                ['label' => 'ژمارەی کەیس', 'value' => $case['case_number']],
                ['label' => 'ناوی کەیس', 'value' => trim((string)($case['case_name'] ?? '')) ?: null],
                $dKind === 'payment'
                    ? ['label' => 'بڕی داواکراو', 'value' => number_format((float)$pay['amount'])]
                    : null,
                $dKind === 'payment'
                    ? ['label' => 'شێوازی پارەدان', 'value' => ($paymentTypeLabels[$pay['payment_type']] ?? $pay['payment_type']) . ' / ' . ($paymentMethodLabels[$pay['payment_method']] ?? $pay['payment_method'])]
                    : null,
            ], function ($r) { return $r !== null && $r['value'] !== null && $r['value'] !== ''; })),
            'notes_history' => $notesHistory,
            'services'      => $services,
            'payments'      => $payments,
            'financial'     => [
                'total'     => $total,
                'paid'      => $paid,
                'discount'  => $discount,
                'remaining' => max(0, $total - $paid - $discount),
            ],
            'page_url' => APP_URL . '/accountant/report-detail.php?case_id=' . $caseId,
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action']);
} catch (Exception $e) {
    error_log('accountant-feed api error [' . $action . ']: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'هەڵەیەک ڕوویدا، دووبارە هەوڵبدەرەوە']);
}
