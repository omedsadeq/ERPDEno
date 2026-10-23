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

/**
 * Summary-strip figures for whatever the current filters select.
 * visits.php renders this same shape server-side; the live search swaps the
 * numbers in from here so the cards never describe a list that is gone.
 */
function visits_summary_zero(): array {
    return [
        'total_visits'     => 0,
        'unique_patients'  => 0,
        'checkup_visits'   => 0,
        'treatment_visits' => 0,
        'new_patients'     => 0,
        'old_patients'     => 0,
        'treated_visits'   => 0,
    ];
}

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

// دکتۆری فەحس / ئیش — degrade gracefully until the migration runs
$hasVisitDoctorCols = false;
try {
    $hasVisitDoctorCols = (bool)Database::getInstance()->fetch("SHOW COLUMNS FROM visits LIKE 'checkup_doctor_id'");
} catch (Exception $e) {}

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

    // A restricted doctor skips Typesense: the index has no checkup/treatment
    // doctor fields, so it would silently drop patients this doctor really has.
    // The MySQL path below covers all four doctor roles.
    try {
        $ts = new TypesenseClient();
        if (!$isRestrictedDoctor && $ts->isAvailable()) {
            $sugParams = [
                'q'        => $q,
                'query_by' => 'patient_name,mobile_number',
                'per_page' => 12,
                'num_typos'=> '2,0',
                'sort_by'  => 'visit_date_ts:desc',
            ];
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
        // Any doctor role on the visit counts as "theirs"
        $whereSql .= ' AND ' . visits_doctor_condition($enforcedDoctorId, '', $paramsS, 'ed', $hasVisitDoctorCols, '');
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
$doctor_field = visits_doctor_field($_GET['doctor_field'] ?? '');
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
    $date_from = $date_to = $status = $visit_type = $doctor_field = '';
    $doctor = 0;
}

// A doctor can only ever see their own visits: override any client-supplied
// doctor filter with their resolved clinical id. Unlinked → fail closed.
if ($isRestrictedDoctor) {
    if ($enforcedDoctorId <= 0) {
        echo json_encode([
            'success' => true, 'visits' => [], 'total' => 0,
            'page' => $page, 'per_page' => $per_page, 'total_pages' => 0, 'source' => 'restricted',
            'summary' => visits_summary_zero(),
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
// Typesense is skipped whenever a doctor filter applies (including a restricted
// doctor's own isolation): the index has no checkup/treatment doctor fields, so
// it can neither express the per-role match nor be safely narrowed afterwards —
// it returns only its top 250 hits, which would silently truncate that doctor's
// list. MySQL below handles those queries end to end.
try {
    $ts = new TypesenseClient();

    if ($ts->isAvailable() && $visit_type === '' && $doctor <= 0) {
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
                'summary'     => visits_summary_zero(),
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
}

// Doctor / role filter — for a restricted doctor $doctor is their own enforced
// id, so this is also what enforces doctor isolation. Kept outside the
// !$use_ts block so it applies even if the Typesense guard above ever changes.
if ($doctor > 0) {
    $where .= ' AND ' . visits_doctor_condition($doctor, $doctor_field, $params, 'doc', $hasVisitDoctorCols);
}

if ($visit_type !== '') {
    $where .= ' AND v.visit_type = :visit_type';
    $params['visit_type'] = $visit_type;
}

// ---------------------------------------------------------------
// Count + fetch
// ---------------------------------------------------------------
// One pass gives both the pagination total and the summary strip, so the cards
// can never disagree with the list they sit above.
$summaryRow = $db->fetch("
    SELECT
        COUNT(*)                                                       AS total_visits,
        COUNT(DISTINCT v.patient_id)                                   AS unique_patients,
        SUM(CASE WHEN v.visit_type <> 'treatment' THEN 1 ELSE 0 END)   AS checkup_visits,
        SUM(CASE WHEN v.visit_type = 'treatment' THEN 1 ELSE 0 END)    AS treatment_visits,
        SUM(CASE WHEN v.patient_status = 'new' THEN 1 ELSE 0 END)      AS new_patients,
        SUM(CASE WHEN v.patient_status = 'old' THEN 1 ELSE 0 END)      AS old_patients,
        SUM(CASE WHEN v.treated = 'yes' THEN 1 ELSE 0 END)             AS treated_visits
    FROM visits v
    WHERE $where
", $params);

$summary = visits_summary_zero();
foreach ($summary as $k => $_) {
    $summary[$k] = (int)($summaryRow[$k] ?? 0);   // SUM() is NULL on an empty set
}
$total = $summary['total_visits'];

$visitDoctorSelect = $hasVisitDoctorCols
    ? "d5.name AS checkup_doctor_name, d6.name AS treatment_doctor_name,"
    : "NULL AS checkup_doctor_name, NULL AS treatment_doctor_name,";
$visitDoctorJoins = $hasVisitDoctorCols
    ? "LEFT JOIN doctors d5 ON v.checkup_doctor_id = d5.id
       LEFT JOIN doctors d6 ON v.treatment_doctor_id = d6.id"
    : "";

$visits_raw = $db->fetchAll("
    SELECT v.id, v.patient_id, v.patient_name, v.mobile_number, v.visit_date,
           v.patient_status, v.visit_type, v.price, v.treated, v.arrival_time, v.checkin_time,
           v.procedure_treatment, v.work_type_id, v.referral_source,
           $visitDoctorSelect
           d1.name AS requested_doctor_name,
           d2.name AS assigned_doctor_name,
           pp.id  AS linked_patient_id,
           (SELECT pmm.id FROM patients pmm WHERE pmm.phone = v.mobile_number LIMIT 1) AS phone_match_patient_id
    FROM visits v
    LEFT JOIN doctors d1 ON v.requested_doctor_id = d1.id
    LEFT JOIN doctors d2 ON v.assigned_doctor_id = d2.id
    $visitDoctorJoins
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
    'summary'     => $summary,
]);
