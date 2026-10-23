<?php
/**
 * Visits Search via Typesense (with MySQL fallback)
 * Returns JSON for AJAX table rendering on visits.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/typesense.php';
require_once __DIR__ . '/../classes/TypesenseClient.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../includes/search_helpers.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// Doctor isolation: a doctor may only search their OWN visits. visits.*_doctor_id
// point at doctors.id, so resolve the logged-in doctor's clinical record (same
// bridge visits.php uses). An unlinked doctor account fails closed (sees nothing).
$isRestrictedDoctor = $auth->isDoctor() && !$auth->isAdmin();
$enforcedDoctorId = 0;
if ($isRestrictedDoctor) {
    $drow = Database::getInstance()->fetch(
        "SELECT id FROM doctors WHERE user_id = :uid
         UNION
         SELECT d.id FROM doctors d JOIN users u ON u.full_name = d.name AND u.id = :uid2
         LIMIT 1",
        ['uid' => (int)($_SESSION['user_id'] ?? 0), 'uid2' => (int)($_SESSION['user_id'] ?? 0)]
    );
    $enforcedDoctorId = (int)($drow['id'] ?? 0);
}

// ---------------------------------------------------------------
// Suggestions mode — returns unique patient names for live dropdown
// ---------------------------------------------------------------
if (($_GET['mode'] ?? '') === 'suggestions') {
    // Unlinked doctor → no visits are theirs → no suggestions.
    if ($isRestrictedDoctor && $enforcedDoctorId <= 0) {
        echo json_encode(['success' => true, 'suggestions' => []]);
        exit;
    }
    $q = search_fold_text(trim($_GET['q'] ?? ''));
    if (mb_strlen($q) < 2) {
        echo json_encode(['success' => true, 'suggestions' => []]);
        exit;
    }

    $suggestions = [];

    try {
        $ts = new TypesenseClient();
        if ($ts->isAvailable()) {
            $sugParams = [
                'q'        => $q,
                'query_by' => 'patient_name,mobile_number',
                'per_page' => 12,
                'num_typos'=> '2,0',
                'sort_by'  => 'visit_date_ts:desc',
            ];
            if ($isRestrictedDoctor) {
                $sugParams['filter_by'] = "(assigned_doctor_id:=$enforcedDoctorId || requested_doctor_id:=$enforcedDoctorId)";
            }
            $result = $ts->search(TYPESENSE_VISITS_COLLECTION, $sugParams);
            $seen = [];
            foreach ($result['hits'] ?? [] as $hit) {
                $doc  = $hit['document'];
                $name = $doc['patient_name'] ?? '';
                if ($name !== '' && !isset($seen[$name])) {
                    $seen[$name]   = true;
                    $suggestions[] = ['name' => $name, 'phone' => $doc['mobile_number'] ?? ''];
                }
                if (count($suggestions) >= 8) break;
            }
            echo json_encode(['success' => true, 'suggestions' => $suggestions]);
            exit;
        }
    } catch (Exception $e) {}

    // MySQL fallback — smart matching (letter-variant + digit folding)
    $db   = Database::getInstance();
    $paramsS = [];
    $whereSql = search_visits_conditions($q, $paramsS, 'q', false, '');
    if ($isRestrictedDoctor) {
        $whereSql .= " AND (requested_doctor_id = :ed OR assigned_doctor_id = :ed2)";
        $paramsS['ed']  = $enforcedDoctorId;
        $paramsS['ed2'] = $enforcedDoctorId;
    }

    $rows = $db->fetchAll(
        "SELECT DISTINCT patient_name, mobile_number FROM visits
         WHERE $whereSql
         ORDER BY patient_name LIMIT 8",
        $paramsS
    );
    foreach ($rows as $r) {
        $suggestions[] = ['name' => $r['patient_name'], 'phone' => $r['mobile_number']];
    }
    echo json_encode(['success' => true, 'suggestions' => $suggestions]);
    exit;
}

$q         = trim($_GET['q'] ?? ($_GET['search'] ?? ''));
$q         = search_fold_text($q);
$date_from = trim($_GET['date_from'] ?? '');
$date_to   = trim($_GET['date_to']   ?? '');
$doctor    = (int)($_GET['doctor']   ?? 0);
$status    = trim($_GET['status']    ?? '');
$visit_type = trim($_GET['visit_type'] ?? '');
if (!in_array($visit_type, ['checkup', 'treatment'], true)) {
    $visit_type = '';
}
$page      = max(1, (int)($_GET['page'] ?? 1));
$per_page  = 10;
$offset    = ($page - 1) * $per_page;

// Independent search: a non-empty search term searches ALL visits — every
// user-facing filter is dropped. Doctor isolation is still re-enforced below.
if ($q !== '') {
    $date_from = $date_to = $status = $visit_type = '';
    $doctor = 0;
}

// A doctor can only ever see their own visits: override any client-supplied
// doctor filter with their resolved clinical id. Unlinked → fail closed.
if ($isRestrictedDoctor) {
    if ($enforcedDoctorId <= 0) {
        echo json_encode([
            'success' => true, 'visits' => [], 'total' => 0,
            'page' => $page, 'per_page' => $per_page, 'total_pages' => 0, 'source' => 'restricted',
        ]);
        exit;
    }
    $doctor = $enforcedDoctorId;
}

$db = Database::getInstance();

$where    = '1=1';
$params   = [];
$use_ts   = false;
$source   = 'mysql';

// ---------------------------------------------------------------
// Try Typesense first
// ---------------------------------------------------------------
try {
    $ts = new TypesenseClient();

    if ($ts->isAvailable() && $visit_type === '') {
        $tsParams = [
            'q'          => ($q !== '') ? $q : '*',
            'query_by'   => 'patient_name,mobile_number',
            'per_page'   => 250,
            'page'       => 1,
            'sort_by'    => 'visit_date_ts:desc',
            'num_typos'  => '2,0',
        ];

        // Build filter_by (all user filters are already cleared when searching)
        $filters = [];
        if ($date_from !== '') {
            $filters[] = 'visit_date_ts:>=' . strtotime($date_from);
        }
        if ($date_to !== '') {
            $filters[] = 'visit_date_ts:<=' . strtotime($date_to . ' 23:59:59');
        }
        if ($status !== '') {
            $filters[] = "patient_status:=$status";
        }
        if ($doctor > 0) {
            $filters[] = "(assigned_doctor_id:=$doctor || requested_doctor_id:=$doctor)";
        }
        if (!empty($filters)) {
            $tsParams['filter_by'] = implode(' && ', $filters);
        }

        $result = $ts->search(TYPESENSE_VISITS_COLLECTION, $tsParams);
        $ids    = array_column(array_column($result['hits'] ?? [], 'document'), 'id');

        if (empty($ids)) {
            echo json_encode([
                'success'     => true,
                'visits'      => [],
                'total'       => 0,
                'page'        => $page,
                'per_page'    => $per_page,
                'total_pages' => 0,
                'source'      => 'typesense',
            ]);
            exit;
        }

        $ids_sql = implode(',', array_map('intval', $ids));
        $where  .= " AND v.id IN ($ids_sql)";
        $use_ts  = true;
        $source  = 'typesense';
    }
} catch (Exception $e) {
    // Silent fallback to MySQL
}

// ---------------------------------------------------------------
// MySQL fallback filters (only when Typesense unavailable)
// ---------------------------------------------------------------
if (!$use_ts) {
    if ($q !== '') {
        $where .= ' AND (' . search_visits_conditions($q, $params, 's') . ')';
    }
    if ($date_from !== '') {
        $where .= ' AND v.visit_date >= :df';
        $params['df'] = $date_from;
    }
    if ($date_to !== '') {
        $where .= ' AND v.visit_date <= :dt';
        $params['dt'] = $date_to;
    }
    if ($status !== '') {
        $where .= ' AND v.patient_status = :ps';
        $params['ps'] = $status;
    }
    if ($doctor > 0) {
        $where .= ' AND (v.assigned_doctor_id = :d1 OR v.requested_doctor_id = :d2)';
        $params['d1'] = $params['d2'] = $doctor;
    }
}

if ($visit_type !== '') {
    $where .= ' AND v.visit_type = :visit_type';
    $params['visit_type'] = $visit_type;
}

// ---------------------------------------------------------------
// Count + fetch
// ---------------------------------------------------------------
$total = (int)($db->fetch("SELECT COUNT(*) AS c FROM visits v WHERE $where", $params)['c'] ?? 0);

$visits_raw = $db->fetchAll("
    SELECT v.id, v.patient_id, v.patient_name, v.mobile_number, v.visit_date,
           v.patient_status, v.visit_type, v.price, v.treated, v.arrival_time, v.checkin_time,
           v.procedure_treatment, v.work_type_id, v.referral_source,
           d1.name AS requested_doctor_name,
           d2.name AS assigned_doctor_name,
           pp.id  AS linked_patient_id,
           (SELECT pmm.id FROM patients pmm WHERE pmm.phone = v.mobile_number LIMIT 1) AS phone_match_patient_id
    FROM visits v
    LEFT JOIN doctors d1 ON v.requested_doctor_id = d1.id
    LEFT JOIN doctors d2 ON v.assigned_doctor_id = d2.id
    LEFT JOIN patients pp ON pp.id = v.patient_id
    WHERE $where
    ORDER BY v.visit_date DESC, v.arrival_time DESC
    LIMIT $per_page OFFSET $offset
", $params);

// Format for front-end
$visits = array_map(function ($v) {
    $v['visit_date_fmt']    = !empty($v['visit_date'])    ? date('Y-m-d', strtotime($v['visit_date']))    : '-';
    $v['arrival_time_fmt']  = !empty($v['arrival_time'])  ? date('h:i A', strtotime($v['arrival_time']))  : '-';
    $v['checkin_time_fmt']  = !empty($v['checkin_time'])  ? date('h:i A', strtotime($v['checkin_time']))  : '-';
    $v['price_fmt']         = number_format((float)($v['price'] ?? 0), 2);
    $v['id']                = (int)$v['id'];
    $v['patient_id']        = (int)($v['patient_id'] ?? 0);
    $v['is_linked']         = !empty($v['linked_patient_id']);
    $v['has_phone_match']   = !$v['is_linked'] && !empty($v['phone_match_patient_id']);
    $v['linked_patient_id'] = (int)($v['linked_patient_id'] ?? 0);
    return $v;
}, $visits_raw);

echo json_encode([
    'success'     => true,
    'visits'      => $visits,
    'total'       => $total,
    'page'        => $page,
    'per_page'    => $per_page,
    'total_pages' => (int)ceil($total / $per_page),
    'source'      => $source,
]);
