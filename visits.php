<?php
/**
 * Visit Management Page
 * سیستەمی زیرەکی کلینیکی ددان
 */

// Handle "sync visit → patients" POST BEFORE any HTML output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sync_to_patient') {
    require_once __DIR__ . '/config/config.php';
    require_once __DIR__ . '/classes/Database.php';
    require_once __DIR__ . '/classes/Auth.php';
    require_once __DIR__ . '/classes/Patient.php';

    $auth = new Auth();
    if (!$auth->isLoggedIn()) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }
    Csrf::require();

    $db = Database::getInstance();
    $visitId = (int)($_POST['visit_id'] ?? 0);
    $gender  = ($_POST['gender'] ?? '') === 'female' ? 'female' : 'male';

    $resp = ['success' => false, 'message' => 'هەڵە'];
    if ($visitId > 0) {
        $visit = $db->fetch("SELECT id, patient_id, patient_name, mobile_number FROM visits WHERE id = :id", ['id' => $visitId]);
        if (!$visit) {
            $resp['message'] = 'سەردان نەدۆزرایەوە';
        } else {
            $linked = null;
            if (!empty($visit['patient_id'])) {
                $linked = $db->fetch("SELECT id FROM patients WHERE id = :id", ['id' => $visit['patient_id']]);
            }
            if ($linked) {
                $resp = ['success' => true, 'message' => 'پێشتر بەشداری کراوە', 'patient_id' => (int)$linked['id'], 'mode' => 'already'];
            } else {
                $match = null;
                if (!empty($visit['mobile_number'])) {
                    $match = $db->fetch("SELECT id FROM patients WHERE phone = :p LIMIT 1", ['p' => $visit['mobile_number']]);
                }
                if ($match) {
                    $db->update('visits', ['patient_id' => $match['id'], 'updated_by' => $_SESSION['user_id'] ?? null], 'id = :id', ['id' => $visitId]);
                    AuditLog::log('link_visit_patient', 'visits', $visitId, ['patient_id' => null], ['patient_id' => (int)$match['id']]);
                    $resp = ['success' => true, 'message' => 'پەیوەست کرا بە نەخۆشێکی هەبوو', 'patient_id' => (int)$match['id'], 'mode' => 'linked'];
                } else {
                    $patientCls = new Patient();
                    $userId = $_SESSION['user_id'] ?? null;
                    $newPatient = $patientCls->create([
                        'full_name'     => trim($visit['patient_name']),
                        'phone'         => trim($visit['mobile_number']),
                        'gender'        => $gender,
                        'registered_by' => $userId,
                    ]);
                    if (!empty($newPatient['success'])) {
                        $db->update('visits', ['patient_id' => $newPatient['patient_id'], 'updated_by' => $_SESSION['user_id'] ?? null], 'id = :id', ['id' => $visitId]);
                        AuditLog::log('create_patient_from_visit', 'visits', $visitId, null, ['patient_id' => (int)$newPatient['patient_id']]);
                        $resp = ['success' => true, 'message' => 'نەخۆش زیاد کرا و پەیوەست کرا', 'patient_id' => (int)$newPatient['patient_id'], 'mode' => 'created'];
                    } else {
                        $resp['message'] = $newPatient['message'] ?? 'هەڵە لە دروستکردنی نەخۆش';
                    }
                }
            }
        }
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($resp);
    exit();
}

$pageTitle = 'سەردانەکان';
$breadcrumb = [['title' => 'سەردانەکان']];

require_once __DIR__ . '/includes/header.php';

$db = Database::getInstance();

// Handle delete request (admin or receptionist) — POST only, CSRF-checked, audited
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_visit') {
    if (!$hasFullAccess) {
        header('Location: ' . APP_URL . '/403.php');
        exit();
    }
    Csrf::require();
    $id = (int)($_POST['visit_id'] ?? 0);
    $oldVisit = $db->fetch("SELECT * FROM visits WHERE id = :id", ['id' => $id]);
    if ($oldVisit) {
        // Keep the full row snapshot in the audit trail before the hard delete
        AuditLog::log('delete_visit', 'visits', $id, $oldVisit);
        $db->delete('visits', 'id = :id', ['id' => $id]);
    }
    header('Location: ' . APP_URL . '/visits.php?deleted=1');
    exit();
}

// Pagination settings
$perPage = 10;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $perPage;

// Build query with filters
require_once __DIR__ . '/includes/search_helpers.php';
$where = "1=1";
$params = [];
$search = '';
$date_from = date('Y-m-01'); // Default to first day of current month
$date_to = '';
$doctor_filter = '';
$doctor_field_filter = '';
$status_filter = '';
$visit_type_filter = '';
$show_today = false;
// دکتۆری فەحس / ئیش — degrade gracefully until the migration runs
$hasVisitDoctorCols = false;
try {
    $hasVisitDoctorCols = (bool)$db->fetch("SHOW COLUMNS FROM visits LIKE 'checkup_doctor_id'");
} catch (Exception $e) {}
// visits.requested_doctor_id holds an id from the `doctors` table, NOT users.id.
// Resolve the logged-in doctor's clinical record via doctors.user_id
// (falling back to the legacy name match for unlinked rows).
$doctor_user_id = 0;
if ($isDoctor) {
    $doctorRow = $db->fetch(
        "SELECT id FROM doctors WHERE user_id = :uid
         UNION
         SELECT d.id FROM doctors d JOIN users u ON u.full_name = d.name AND u.id = :uid2
         LIMIT 1",
        ['uid' => (int)($currentUser['id'] ?? 0), 'uid2' => (int)($currentUser['id'] ?? 0)]
    );
    $doctor_user_id = (int)($doctorRow['id'] ?? 0);
}

// Accept both `search` (form field) and `q` (URL written by the live-search JS)
$rawSearch = trim($_GET['search'] ?? ($_GET['q'] ?? ''));
$is_search_active = $rawSearch !== '';

// ── Quick date ranges (ئەمڕۆ / دوێنێ / ئەم هەفتەیە / ئەم مانگە) ──
// The week runs Saturday→Friday, the same Kurdish calendar convention
// appointments.php uses. ?today=1 is kept working for old links/bookmarks.
$range_filter = '';
$rawRange = $_GET['range'] ?? (isset($_GET['today']) ? 'today' : '');
if (in_array($rawRange, ['today', 'yesterday', 'week', 'month'], true)) {
    $range_filter = $rawRange;
}

$range_from = $range_to = '';
if ($range_filter !== '') {
    switch ($range_filter) {
        case 'today':
            $range_from = $range_to = date('Y-m-d');
            break;
        case 'yesterday':
            $range_from = $range_to = date('Y-m-d', strtotime('-1 day'));
            break;
        case 'week':
            $daysToSaturday = ((int)date('w') + 1) % 7;
            $range_from = date('Y-m-d', strtotime("-$daysToSaturday days"));
            $range_to   = date('Y-m-d', strtotime($range_from . ' +6 days'));
            break;
        case 'month':
            $range_from = date('Y-m-01');
            $range_to   = date('Y-m-t');
            break;
    }
}

if ($range_filter !== '') {
    $show_today = ($range_filter === 'today'); // the per-doctor report below
    if (!$is_search_active) {
        $where .= " AND v.visit_date BETWEEN :range_from AND :range_to";
        $params['range_from'] = $range_from;
        $params['range_to']   = $range_to;
    }
    // A quick range owns the date boxes — show it there and skip the manual ones
    $date_from = $range_from;
    $date_to   = $range_to;
}

if ($is_search_active) {
    // Independent smart search: searches ALL visits, ignores every other filter
    // (doctor isolation for restricted doctors is still enforced below).
    $search = $rawSearch;
    $where .= ' AND (' . search_visits_conditions($search, $params, 's') . ')';
}

// Manual date boxes — ignored while a quick range is active (it already
// bound its own BETWEEN above) and while searching.
if ($range_filter === '' && isset($_GET['date_from'])) {
    $date_from = trim($_GET['date_from']);
}

if ($range_filter === '' && !empty($date_from) && !$is_search_active) {
    $where .= " AND v.visit_date >= :date_from";
    $params['date_from'] = $date_from;
}

if ($range_filter === '' && isset($_GET['date_to']) && !empty($_GET['date_to']) && !$is_search_active) {
    $date_to = trim($_GET['date_to']);
    $where .= " AND v.visit_date <= :date_to";
    $params['date_to'] = $date_to;
}

// Which doctor column the دکتۆر box matches: any role, بینراو, فەحس or ئیش.
// Cleared while searching, like every other filter.
if (!$is_search_active) {
    $doctor_field_filter = visits_doctor_field($_GET['doctor_field'] ?? '');
}

if ($isDoctor && $doctor_user_id > 0) {
    // Doctor isolation: a doctor sees a visit when they hold ANY doctor role on
    // it (داواکراو / بینراو / فەحس / ئیش) — narrowed further if they picked a
    // specific role in the combobox. Never widens past their own id.
    $doctor_filter = $doctor_user_id;
    $where .= ' AND ' . visits_doctor_condition($doctor_user_id, $doctor_field_filter, $params, 'own', $hasVisitDoctorCols);
} elseif ($isDoctor) {
    // Doctor account without a linked `doctors` record: fail closed —
    // never expose other doctors' visits. Admin must link the account
    // (doctors.user_id) for this doctor to see their visit list.
    $where .= " AND 1 = 0";
} elseif (!$is_search_active && isset($_GET['doctor']) && !empty($_GET['doctor'])) {
    $doctor_filter = (int)$_GET['doctor'];
    $where .= ' AND ' . visits_doctor_condition($doctor_filter, $doctor_field_filter, $params, 'doc', $hasVisitDoctorCols);
}

if (!$is_search_active && isset($_GET['status']) && !empty($_GET['status'])) {
    $status_filter = trim($_GET['status']);
    $where .= " AND v.patient_status = :patient_status";
    $params['patient_status'] = $status_filter;
}

if (!$is_search_active && isset($_GET['visit_type']) && $_GET['visit_type'] !== '') {
    $visit_type_filter = trim($_GET['visit_type']);
    if (in_array($visit_type_filter, ['checkup', 'treatment'], true)) {
        $where .= " AND v.visit_type = :visit_type";
        $params['visit_type'] = $visit_type_filter;
    } else {
        $visit_type_filter = '';
    }
}

// Get total count for pagination
$countQuery = "SELECT COUNT(*) as total FROM visits v WHERE $where";
$countResult = $db->fetch($countQuery, $params);
$totalVisits = $countResult['total'] ?? 0;
$totalPages = ceil($totalVisits / $perPage);

$visitDoctorSelect = $hasVisitDoctorCols
    ? "d5.name as checkup_doctor_name, d6.name as treatment_doctor_name,"
    : "NULL as checkup_doctor_name, NULL as treatment_doctor_name,";
$visitDoctorJoins = $hasVisitDoctorCols
    ? "LEFT JOIN doctors d5 ON v.checkup_doctor_id = d5.id
       LEFT JOIN doctors d6 ON v.treatment_doctor_id = d6.id"
    : "";

// Get visits with filters and pagination
$query = "
    SELECT v.*,
           $visitDoctorSelect
           d1.name as requested_doctor_name,
           d2.name as assigned_doctor_name,
           d3.name as appointment_doctor_name,
           d4.name as referring_doctor_name,
           u.full_name as receptionist_name,
           DATE_FORMAT(v.appointment_date, '%Y-%m-%d') as appointment,
           pp.id           AS linked_patient_id,
           pp.patient_code AS linked_patient_code,
           (SELECT pmm.id FROM patients pmm WHERE pmm.phone = v.mobile_number LIMIT 1) AS phone_match_patient_id,
           (
              SELECT CONCAT(a.appointment_date, '|', a.appointment_time, '|', a.status, '|', COALESCE(ud.full_name, ''))
              FROM appointments a
              LEFT JOIN users ud ON a.doctor_id = ud.id
              WHERE a.patient_id = v.patient_id
                AND a.appointment_date >= CURDATE()
                AND a.status NOT IN ('cancelled','no_show','completed')
              ORDER BY a.appointment_date ASC, a.appointment_time ASC
              LIMIT 1
           ) AS upcoming_apt
    FROM visits v
    LEFT JOIN doctors d1 ON v.requested_doctor_id = d1.id
    LEFT JOIN doctors d2 ON v.assigned_doctor_id = d2.id
    LEFT JOIN doctors d3 ON v.appointment_doctor_id = d3.id
    LEFT JOIN doctors d4 ON v.referring_doctor_id = d4.id
    $visitDoctorJoins
    LEFT JOIN users u ON v.receptionist_id = u.id
    LEFT JOIN patients pp ON pp.id = v.patient_id
    WHERE $where
    ORDER BY v.visit_date DESC, v.arrival_time DESC
    LIMIT $perPage OFFSET $offset
";

$visits = $db->fetchAll($query, $params);

// Get doctors for filter dropdown
$doctors = $db->fetchAll("SELECT id, name FROM doctors WHERE is_active = 1 ORDER BY name");

// Appointment-modal lookups (users table — matches appointments/schedule system)
$doctors_for_modal    = $db->fetchAll("SELECT id, full_name AS name FROM users WHERE role='doctor' AND is_active=1 ORDER BY full_name");
// X-ray department accounts: selectable in the doctor box for x-ray-only patients
$xray_accounts_for_modal = $db->fetchAll("SELECT id, full_name AS name FROM users WHERE role='xray_department' AND is_active=1 ORDER BY full_name");
$work_types_for_modal = $db->fetchAll(
    "SELECT id, work_name FROM work_types
     WHERE is_active = 1 AND " . work_type_scope_sql('visits') . "
     ORDER BY sort_order, id"
);

// Kurdish duration labels 15m → 12h
if (!function_exists('dh_duration_options_visits')) {
    function dh_duration_options_visits($selected = 30) {
        $kd = function($n) {
            return strtr((string)$n, ['0'=>'٠','1'=>'١','2'=>'٢','3'=>'٣','4'=>'٤','5'=>'٥','6'=>'٦','7'=>'٧','8'=>'٨','9'=>'٩']);
        };
        $durations = [15, 30, 45];
        for ($m = 60; $m <= 720; $m += 30) { $durations[] = $m; }
        $out = '';
        foreach ($durations as $d) {
            if ($d < 60) { $label = $kd($d) . ' خولەک'; }
            else { $h = intdiv($d, 60); $r = $d % 60; $label = $r === 0 ? $kd($h) . ' کاتژمێر' : $kd($h) . ' کاتژمێر و ' . $kd($r) . ' خولەک'; }
            $sel = ($d == $selected) ? ' selected' : '';
            $out .= '<option value="' . $d . '"' . $sel . '>' . $label . '</option>';
        }
        return $out;
    }
}

// Get statistics for today if today filter is active
$today_stats = null;
$doctors_report = null;
$procedure_breakdown = [];
$source_breakdown = [];

// Summary strip — deliberately built from the SAME $where/$params as the list
// above, so it always describes exactly what is on screen (any filter, any
// range, any search). The old version ran its own "today" query filtered on
// requested_doctor_id, which is NULL on every treatment visit — that is why a
// doctor saw 0 patients while the waiting room showed ten.
$summary_stats = $db->fetch("
    SELECT
        COUNT(*)                                             AS total_visits,
        SUM(CASE WHEN v.patient_status = 'new' THEN 1 ELSE 0 END)      AS new_patients,
        SUM(CASE WHEN v.patient_status = 'old' THEN 1 ELSE 0 END)      AS old_patients,
        SUM(CASE WHEN v.visit_type = 'treatment' THEN 1 ELSE 0 END)    AS treatment_visits,
        SUM(CASE WHEN v.visit_type <> 'treatment' THEN 1 ELSE 0 END)   AS checkup_visits,
        SUM(CASE WHEN v.treated = 'yes' THEN 1 ELSE 0 END)             AS treated_visits,
        COUNT(DISTINCT v.patient_id)                                   AS unique_patients
    FROM visits v
    WHERE $where
", $params);

// A visit's effective doctor: the most specific role that is filled in.
// Treatment visits carry ONLY treatment_doctor_id, so the old reports — which
// joined on requested_doctor_id — counted them as nobody's.
$effectiveDoctorExpr = $hasVisitDoctorCols
    ? "COALESCE(v.treatment_doctor_id, v.checkup_doctor_id, v.assigned_doctor_id, v.requested_doctor_id)"
    : "COALESCE(v.assigned_doctor_id, v.requested_doctor_id)";

if ($show_today) {
    $today_date = date('Y-m-d');

    // Per-doctor report for today, keyed off the effective doctor
    if ($isDoctor && $doctor_user_id > 0) {
        $doctors_report_query = "
            SELECT
                d.id as doctor_id,
                d.name as doctor_name,
                COUNT(v.id) as total_patients,
                SUM(CASE WHEN v.requested_doctor_id = d.id THEN 1 ELSE 0 END) as requested_patients,
                SUM(CASE WHEN v.assigned_doctor_id = d.id THEN 1 ELSE 0 END) as assigned_patients,
                SUM(CASE WHEN v.patient_status = 'new' THEN 1 ELSE 0 END) as new_patients,
                SUM(CASE WHEN v.patient_status = 'old' THEN 1 ELSE 0 END) as old_patients,
                SUM(CASE WHEN v.treated = 'yes' THEN 1 ELSE 0 END) as treated_patients
            FROM visits v
            INNER JOIN doctors d ON d.id = $effectiveDoctorExpr
            WHERE v.visit_date = :today_date AND d.id = :doctor_id
            GROUP BY d.id, d.name
            ORDER BY total_patients DESC
        ";
        $doctors_report = $db->fetchAll($doctors_report_query, [
            'today_date' => $today_date,
            'doctor_id' => $doctor_user_id
        ]);
    } else {
        $doctors_report_query = "
            SELECT
                d.id as doctor_id,
                d.name as doctor_name,
                COUNT(v.id) as total_patients,
                SUM(CASE WHEN v.requested_doctor_id = d.id THEN 1 ELSE 0 END) as requested_patients,
                SUM(CASE WHEN v.assigned_doctor_id = d.id THEN 1 ELSE 0 END) as assigned_patients,
                SUM(CASE WHEN v.patient_status = 'new' THEN 1 ELSE 0 END) as new_patients,
                SUM(CASE WHEN v.patient_status = 'old' THEN 1 ELSE 0 END) as old_patients,
                SUM(CASE WHEN v.treated = 'yes' THEN 1 ELSE 0 END) as treated_patients
            FROM visits v
            INNER JOIN doctors d ON d.id = $effectiveDoctorExpr
            WHERE v.visit_date = :today_date
            GROUP BY d.id, d.name
            ORDER BY total_patients DESC
        ";
        $doctors_report = $db->fetchAll($doctors_report_query, ['today_date' => $today_date]);
    }

    // Get procedure and source breakdowns for each doctor
    if (!empty($doctors_report)) {
        foreach ($doctors_report as $doc) {
            $doctor_id = $doc['doctor_id'];

            // Get procedure breakdown
            $proc_query = "
                SELECT procedure_treatment, COUNT(*) as count
                FROM visits v
                WHERE visit_date = :today_date
                AND $effectiveDoctorExpr = :doctor_id
                AND procedure_treatment IS NOT NULL AND procedure_treatment != ''
                GROUP BY procedure_treatment
            ";
            $procedure_breakdown[$doctor_id] = $db->fetchAll($proc_query, [
                'today_date' => $today_date,
                'doctor_id' => $doctor_id
            ]);

            // Get referral source breakdown
            $source_query = "
                SELECT referral_source, COUNT(*) as count
                FROM visits v
                WHERE visit_date = :today_date
                AND $effectiveDoctorExpr = :doctor_id
                AND referral_source IS NOT NULL AND referral_source != ''
                GROUP BY referral_source
            ";
            $source_breakdown[$doctor_id] = $db->fetchAll($source_query, [
                'today_date' => $today_date,
                'doctor_id' => $doctor_id
            ]);
        }
    }
}

// Helper functions
function formatVisitTime($time) {
    if (empty($time)) return '-';
    return date('h:i A', strtotime($time));
}

function formatVisitDate($date) {
    if (empty($date)) return '-';
    return date('Y-m-d', strtotime($date));
}

function formatVisitPrice($price, $visitType = 'checkup') {
    if ($visitType === 'treatment') return '-';
    return number_format((float)($price ?? 0), 2);
}

// Build pagination URL
function buildPaginationUrl($pageNum) {
    $params = $_GET;
    $params['page'] = $pageNum;
    return '?' . http_build_query($params);
}
?>

<style>
/* Visits Page Styles - Matching Debts Design */
.page-content {
    padding-left: 0 !important;
    padding-right: 0 !important;
}

.visits-container {
    width: 100%;
    max-width: none;
    margin: 0;
    box-sizing: border-box;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 16px;
}

.page-title {
    display: flex;
    align-items: center;
    gap: 12px;
}

.page-title h2 {
    margin: 0;
    font-size: 22px;
    color: #1a1a1a;
}

.page-title .icon {
    width: 50px;
    height: 50px;
    background: linear-gradient(135deg, #2196f3, #1976d2);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 22px;
    box-shadow: 0 4px 12px rgba(33, 150, 243, 0.3);
}

.page-actions {
    display: flex;
    gap: 10px;
}

.btn-add {
    padding: 10px 20px;
    background: linear-gradient(135deg, #4caf50, #388e3c);
    color: white;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-size: 14px;
    font-family: inherit;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
    transition: all 0.2s;
    box-shadow: 0 2px 8px rgba(76, 175, 80, 0.3);
}

.btn-add:hover {
    background: linear-gradient(135deg, #388e3c, #2e7d32);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(76, 175, 80, 0.4);
}

/* Filters */
.filters-section {
    background: white;
    border-radius: 12px;
    padding: 16px 20px;
    margin-bottom: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}

.filters-row {
    display: flex;
    gap: 12px;
    align-items: center;
    flex-wrap: wrap;
}

.filter-group {
    display: flex;
    align-items: center;
    gap: 8px;
}

.filter-group label {
    font-size: 13px;
    color: #666;
    font-weight: 600;
    white-space: nowrap;
}

.filter-group select,
.filter-group input {
    padding: 8px 12px;
    border: 1.5px solid #e0e0e0;
    border-radius: 6px;
    font-size: 13px;
    font-family: inherit;
    min-width: 150px;
}

.filter-group select:focus,
.filter-group input:focus {
    border-color: #2196f3;
    outline: none;
}

.btn-filter {
    padding: 8px 16px;
    background: linear-gradient(135deg, #2196f3, #1976d2);
    color: white;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 13px;
    font-family: inherit;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
}

.btn-filter:hover {
    background: linear-gradient(135deg, #1976d2, #1565c0);
}

.btn-today {
    padding: 8px 16px;
    background: linear-gradient(135deg, #4caf50, #388e3c);
    color: white;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 13px;
    font-family: inherit;
    display: flex;
    align-items: center;
    gap: 6px;
    text-decoration: none;
    transition: all 0.2s;
}

.btn-today:hover {
    background: linear-gradient(135deg, #388e3c, #2e7d32);
}

.btn-reset {
    padding: 8px 16px;
    background: #6c757d;
    color: white;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 13px;
    font-family: inherit;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 6px;
}

.btn-reset:hover {
    background: #5a6268;
}

/* Visits Table */
.visits-table-section {
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 2px 12px rgba(0,0,0,0.08);
}

.table-header {
    background: linear-gradient(135deg, #f5f5f5, #eeeeee);
    padding: 16px 20px;
    border-bottom: 2px solid #e0e0e0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.table-title {
    font-size: 16px;
    font-weight: 700;
    color: #333;
    display: flex;
    align-items: center;
    gap: 8px;
}

.table-count {
    background: #2196f3;
    color: white;
    padding: 2px 10px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}

.table-actions {
    display: flex;
    gap: 8px;
}

.btn-print {
    padding: 8px 14px;
    background: #607d8b;
    color: white;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 12px;
    font-family: inherit;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
}

.btn-print:hover {
    background: #546e7a;
}

/* overflow-y:clip keeps this a horizontal-only scroller, so the sticky <th>
   below anchors to the viewport instead of this box (plain overflow-x:auto
   would compute overflow-y:auto and kill the stick). Older browsers that
   don't know `clip` simply drop the line and lose only the sticky header. */
.visits-table-scroll {
    overflow-x: auto;
    overflow-y: clip;
}

.visits-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    font-size: 13px;
}

.visits-table thead {
    background: transparent;
}

.visits-table th {
    position: sticky;
    top: 0;
    z-index: 2;
    padding: 11px 10px;
    text-align: center;
    font-weight: 700;
    font-size: 11.5px;
    color: #64748b;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    white-space: nowrap;
}

.visits-table td {
    padding: 9px 10px;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
    text-align: center;
}

.visits-table tbody tr {
    transition: background 0.15s;
}

.visits-table tbody tr:nth-child(even) {
    background: #fcfdfe;
}

.visits-table tbody tr:hover {
    background: #f0f7ff;
}

.patient-info {
    display: flex;
    align-items: center;
    gap: 10px;
    justify-content: flex-start;
}

.patient-avatar {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: linear-gradient(135deg, #2196f3, #1976d2);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 14px;
    flex-shrink: 0;
}

.patient-details {
    display: flex;
    flex-direction: column;
    gap: 2px;
    text-align: right;
}

.patient-name {
    font-weight: 600;
    color: #333;
}

.patient-phone {
    font-size: 12px;
    color: #666;
    display: flex;
    align-items: center;
    gap: 4px;
}

.patient-phone i {
    color: #4caf50;
    font-size: 11px;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}

.status-badge.new {
    background: #e8f5e9;
    color: #2e7d32;
}

.status-badge.old {
    background: #e3f2fd;
    color: #1976d2;
}

.status-badge.treated-yes {
    background: #e8f5e9;
    color: #2e7d32;
}

.status-badge.treated-no {
    background: #fff3e0;
    color: #f57c00;
}

.status-badge.visit-checkup {
    background: #e0f2fe;
    color: #0369a1;
}

.status-badge.visit-treatment {
    background: #f3e8ff;
    color: #7e22ce;
}

.doctor-name {
    font-size: 12px;
    color: #555;
}

/* د. فەحس / ئیش — one merged column, thin chips with distinct backgrounds */
.doc-chips {
    display: flex;
    flex-direction: column;
    gap: 3px;
    align-items: center;
}
.doc-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 9px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 600;
    white-space: nowrap;
    line-height: 1.7;
}
.doc-chip i {
    font-size: 9px;
    opacity: 0.75;
}
.doc-chip-checkup {
    background: #e0f2fe;
    color: #0369a1;
    border: 1px solid #bae6fd;
}
.doc-chip-treatment {
    background: #f3e8ff;
    color: #7e22ce;
    border: 1px solid #e9d5ff;
}

.time-cell {
    font-family: var(--app-font-family);
    font-size: 12px;
    color: #666;
}

.procedure-cell {
    max-width: 120px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    font-size: 12px;
}

.price-cell {
    font-family: var(--app-font-family);
    font-size: 12px;
    color: #444;
    white-space: nowrap;
}

.action-buttons {
    display: flex;
    gap: 6px;
    justify-content: center;
}

.btn-action {
    padding: 6px 12px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 12px;
    font-family: inherit;
    display: flex;
    align-items: center;
    gap: 4px;
    transition: all 0.2s;
}

.btn-edit {
    background: #2196f3;
    color: white;
}

.btn-edit:hover {
    background: #1976d2;
}

.btn-delete {
    background: #f44336;
    color: white;
}

.btn-delete:hover {
    background: #d32f2f;
}

/* Pagination */
.pagination-section {
    padding: 16px 20px;
    background: #fafafa;
    border-top: 1px solid #e0e0e0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}

.pagination-info {
    font-size: 13px;
    color: #666;
}

.pagination-info strong {
    color: #333;
}

.pagination-controls {
    display: flex;
    gap: 4px;
    align-items: center;
}

.pagination-btn {
    padding: 8px 12px;
    border: 1px solid #e0e0e0;
    background: white;
    color: #333;
    border-radius: 6px;
    cursor: pointer;
    font-size: 13px;
    font-family: inherit;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 4px;
    transition: all 0.2s;
}

.pagination-btn:hover:not(.disabled):not(.active) {
    background: #f5f5f5;
    border-color: #2196f3;
}

.pagination-btn.active {
    background: linear-gradient(135deg, #2196f3, #1976d2);
    color: white;
    border-color: #2196f3;
}

.pagination-btn.disabled {
    opacity: 0.5;
    cursor: not-allowed;
    pointer-events: none;
}

.pagination-ellipsis {
    padding: 8px 12px;
    color: #999;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #999;
}

.empty-state i {
    font-size: 64px;
    color: #ddd;
    margin-bottom: 16px;
}

.empty-state h3 {
    font-size: 18px;
    color: #666;
    margin: 0 0 8px;
}

.empty-state p {
    font-size: 14px;
    color: #999;
    margin: 0;
}

/* Today Stats */
.today-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 12px;
    margin-bottom: 16px;
}

.today-stat-card {
    padding: 16px;
    border-radius: 10px;
    color: white;
    text-align: center;
}

.today-stat-card.stat-total {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

.today-stat-card.stat-new {
    background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
}

.today-stat-card.stat-old {
    background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
}

.today-stat-label {
    font-size: 12px;
    opacity: 0.9;
    margin-bottom: 4px;
}

.today-stat-value {
    font-size: 28px;
    font-weight: 700;
}

/* Report Table */
.report-section {
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 2px 12px rgba(0,0,0,0.08);
    margin-bottom: 20px;
}

.report-header {
    background: linear-gradient(135deg, #673ab7, #512da8);
    padding: 14px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.report-title {
    font-size: 15px;
    font-weight: 700;
    color: white;
    display: flex;
    align-items: center;
    gap: 8px;
}

.report-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
}

.report-table thead {
    background: #fafafa;
}

.report-table th {
    padding: 12px 10px;
    font-weight: 600;
    text-align: center;
    white-space: nowrap;
    border-bottom: 2px solid #e0e0e0;
}

.report-table tbody tr {
    border-bottom: 1px solid #f0f0f0;
}

.report-table tbody tr:hover {
    background: #fafafa;
}

.report-table td {
    padding: 12px 10px;
    text-align: center;
    vertical-align: middle;
}

.chip-group {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    justify-content: center;
    max-width: 180px;
    margin: 0 auto;
}

.chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 8px;
    background: #f5f5f5;
    border-radius: 6px;
    font-size: 11px;
    color: #333;
}

.chip-count {
    background: #2196f3;
    color: white;
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 10px;
    font-weight: 600;
}

/* ── Quick date ranges ───────────────────────────────────── */
.range-row {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px solid #eef2f6;
}
.range-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border: 1px solid #dbe3ea;
    border-radius: 18px;
    background: #fff;
    color: #475569;
    font-size: 12.5px;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.15s;
}
.range-chip i { font-size: 11px; opacity: 0.7; }
.range-chip:hover {
    border-color: #2196f3;
    color: #1976d2;
    background: #f5faff;
}
.range-chip.is-active {
    background: linear-gradient(135deg, #2196f3, #1976d2);
    border-color: #1976d2;
    color: #fff;
}
.range-chip.is-active i { opacity: 1; }
.range-dates {
    font-size: 11.5px;
    color: #94a3b8;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.range-dates i { font-size: 10px; }

/* ── Summary strip — describes the CURRENT filter, not a fixed day ── */
.summary-strip {
    display: flex;
    align-items: stretch;
    gap: 2px;
    flex-wrap: wrap;
    background: #fff;
    border: 1px solid #e8eef4;
    border-radius: 10px;
    padding: 4px 6px;
    margin-bottom: 16px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.04);
}
.summary-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 1px;
    padding: 7px 16px;
    border-radius: 7px;
    min-width: 74px;
}
.summary-value {
    font-size: 17px;
    font-weight: 700;
    color: #1e293b;
    line-height: 1.1;
}
.summary-label {
    font-size: 10.5px;
    color: #94a3b8;
    font-weight: 600;
}
.summary-sep {
    width: 1px;
    background: #eef2f6;
    margin: 7px 5px;
}
.summary-total          { background: #eff6ff; }
.summary-total .summary-value { color: #1d4ed8; }
.summary-checkup .summary-value   { color: #0369a1; }
.summary-treatment .summary-value { color: #7e22ce; }
.summary-new .summary-value       { color: #2e7d32; }
.summary-old .summary-value       { color: #1976d2; }
.summary-treated .summary-value   { color: #b45309; }
.summary-print {
    margin-right: auto;
    align-self: center;
    background: none;
    border: 1px solid #e2e8f0;
    color: #94a3b8;
    border-radius: 7px;
    padding: 7px 11px;
    cursor: pointer;
    font-size: 13px;
    transition: all 0.15s;
}
.summary-print:hover { background: #f8fafc; color: #475569; }

@media (max-width: 767px) {
    .summary-strip { justify-content: space-around; }
    .summary-item { min-width: 62px; padding: 6px 8px; }
    .summary-sep { display: none; }
    .summary-print { margin-right: 0; }
    .range-chip { flex: 1 1 calc(50% - 6px); justify-content: center; }
    .range-dates { flex: 1 1 100%; justify-content: center; }
}

/* ── Live Search Suggestions ─────────────────────────────── */
.search-fg { position: relative; }

/* Independent search: filters visibly locked while a search term is active */
.filter-group.filter-locked { opacity: 0.45; }
.filter-group.filter-locked select,
.filter-group.filter-locked input {
    pointer-events: none;
    background: #f3f4f6;
}
.visits-search-hint {
    display: block;
    margin-top: 4px;
    font-size: 11px;
    color: #2196f3;
    font-weight: 600;
}

.visits-suggestions-box {
    position: absolute;
    top: calc(100% + 4px);
    right: 0;
    left: 0;
    min-width: 280px;
    background: #fff;
    border: 1px solid #e0e0e0;
    border-radius: 10px;
    box-shadow: 0 8px 28px rgba(0,0,0,0.13);
    z-index: 9999;
    overflow: hidden;
}
.visits-suggestion-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 14px;
    cursor: pointer;
    border-bottom: 1px solid #f5f5f5;
    transition: background 0.12s;
}
.visits-suggestion-item:last-child { border-bottom: none; }
.visits-suggestion-item:hover,
.visits-suggestion-item.sug-active { background: #e3f2fd; }
.visits-suggestion-item .sug-icon { color: #2196f3; font-size: 12px; }
.visits-suggestion-item .sug-name { font-weight: 600; font-size: 13px; color: #1a1a1a; flex: 1; }
.visits-suggestion-item .sug-phone { font-size: 12px; color: #999; }

/* ── Load More / Infinite Scroll ─────────────────────────── */
#visits-load-section {
    text-align: center;
    padding: 18px 0 8px;
}
#btn-load-more {
    padding: 9px 30px;
    background: #fff;
    border: 2px solid #2196f3;
    color: #2196f3;
    border-radius: 24px;
    font-size: 13px;
    font-family: inherit;
    cursor: pointer;
    font-weight: 600;
    transition: all 0.2s;
}
#btn-load-more:hover { background: #2196f3; color: #fff; }
#btn-load-more:disabled { opacity: .5; cursor: default; }

#visits-spinner {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 8px;
    color: #888;
    font-size: 13px;
}
.spinner-ring {
    width: 20px; height: 20px;
    border: 2.5px solid #e0e0e0;
    border-top-color: #2196f3;
    border-radius: 50%;
    animation: ts-spin 0.7s linear infinite;
}
@keyframes ts-spin { to { transform: rotate(360deg); } }

#visits-end-msg { color: #bbb; font-size: 13px; margin: 0; }

/* ── Responsive ───────────────────────────────────────────── */
/* Tablet (768–1024px) — refine but keep horizontal layout */
@media (min-width: 768px) and (max-width: 1024px) {
    .filter-group select,
    .filter-group input { min-width: 130px; }
    .visits-table th,
    .visits-table td { padding: 10px 8px; font-size: 12px; }
    .today-stats-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
}

/* Mobile (≤767px) */
@media (max-width: 767px) {
    /* Page header — title + actions stack, actions become 2-col grid */
    .page-header {
        flex-direction: column;
        align-items: stretch;
        gap: 12px;
        padding: 12px;
    }
    .page-title h2 { font-size: 18px; }
    .page-actions {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px;
        width: 100%;
    }
    .btn-add {
        justify-content: center;
        padding: 11px 8px;
        font-size: 13px;
        min-height: 42px;
        text-align: center;
    }

    /* Filters: stack vertically, full-width controls */
    .filters-section { padding: 12px; }
    .filters-row {
        flex-direction: column;
        align-items: stretch;
        gap: 10px;
    }
    .filter-group {
        flex-direction: column;
        align-items: stretch;
        gap: 4px;
        width: 100%;
    }
    .filter-group label { font-size: 12px; }
    .filter-group select,
    .filter-group input {
        width: 100%;
        min-width: 0 !important;
        font-size: 16px; /* prevent iOS zoom on focus */
        padding: 10px 12px;
        min-height: 42px;
    }
    .btn-filter,
    .btn-today,
    .btn-reset {
        justify-content: center;
        min-height: 42px;
        flex: 1 1 calc(33.333% - 6px);
        font-size: 13px;
    }

    /* Today stats: 1 column */
    .today-stats-grid { grid-template-columns: 1fr; }
    .today-stat-value { font-size: 24px; }

    /* Table-header (title + print button) stacks */
    .table-header {
        flex-direction: column;
        align-items: stretch;
        gap: 10px;
        padding: 12px;
    }
    .table-actions {
        width: 100%;
        flex-wrap: wrap;
    }
    .btn-print {
        flex: 1;
        justify-content: center;
        min-height: 38px;
    }

    /* Report header (purple bar) stacks */
    .report-header {
        flex-direction: column;
        align-items: stretch;
        gap: 8px;
        padding: 12px;
    }

    /* Visits table — global initResponsiveTables() converts to cards.
       Remove the font-size shrink so card rows render at normal size. */
    .visits-table { font-size: 13px; }
    .visits-table th,
    .visits-table td { padding: 8px 12px; }

    /* Action buttons inside table cards: full-width grid for thumb taps */
    .action-buttons {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 6px;
        justify-content: stretch;
    }
    .btn-action {
        padding: 9px 8px;
        font-size: 12px;
        justify-content: center;
        min-height: 38px;
    }

    /* Pagination */
    .pagination-section {
        flex-direction: column;
        text-align: center;
        padding: 12px;
        gap: 10px;
    }
    .pagination-controls {
        justify-content: center;
        flex-wrap: wrap;
        gap: 4px;
    }
    .pagination-btn {
        min-width: 40px;
        min-height: 40px;
        justify-content: center;
        padding: 7px 10px;
    }

    /* Search suggestions stay full-width */
    .visits-suggestions-box {
        right: 0;
        left: 0;
        min-width: 0;
        max-height: 280px;
        overflow-y: auto;
    }
}

/* Small mobile (≤480px) — tighten further */
@media (max-width: 480px) {
    .page-actions { grid-template-columns: 1fr; }
    .btn-filter,
    .btn-today,
    .btn-reset { flex: 1 1 100%; }
    .table-title { font-size: 14px; }
    .visits-table th,
    .visits-table td { padding: 6px 10px; font-size: 12px; }
}

/* Print styles */
@media print {
    .filters-section,
    .page-header .page-actions,
    .action-buttons,
    .pagination-section,
    .btn-print {
        display: none !important;
    }

    .visits-table-section {
        box-shadow: none !important;
        border: 1px solid #ddd !important;
    }
}

/* Add to Waiting Button */
.btn-waiting {
    background: linear-gradient(135deg, #9c27b0, #7b1fa2);
    color: white;
}

.btn-waiting:hover {
    background: linear-gradient(135deg, #7b1fa2, #6a1b9a);
}

.btn-waiting.in-waiting {
    background: #e0e0e0;
    color: #999;
    cursor: not-allowed;
}

/* "Send to Waiting Room" action button (cyan) */
.btn-to-waiting {
    background: linear-gradient(135deg, #00bcd4, #0097a7);
    color: white;
}
.btn-to-waiting:hover {
    background: linear-gradient(135deg, #0097a7, #006064);
}

/* Sync to patients */
.btn-sync-patient {
    background: linear-gradient(135deg, #f44336, #c62828);
    color: white;
}
.btn-sync-patient:hover {
    background: linear-gradient(135deg, #c62828, #8e0000);
}
.btn-sync-patient.has-match {
    background: linear-gradient(135deg, #ff9800, #ef6c00);
}
.btn-sync-patient.has-match:hover {
    background: linear-gradient(135deg, #ef6c00, #b53d00);
}
.btn-view-patient {
    background: linear-gradient(135deg, #4caf50, #2e7d32);
    color: white;
    text-decoration: none;
}
.btn-view-patient:hover {
    background: linear-gradient(135deg, #2e7d32, #1b5e20);
    color: white;
}

/* Share status badge inside patient cell */
.share-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    margin-top: 4px;
    padding: 2px 8px;
    font-size: 10px;
    font-weight: 600;
    border-radius: 10px;
    border: 1px solid;
    white-space: nowrap;
}
.share-badge.share-linked {
    background: #e8f5e9;
    color: #2e7d32;
    border-color: #a5d6a7;
}
.share-badge.share-match {
    background: #fff3e0;
    color: #ef6c00;
    border-color: #ffcc80;
}
.share-badge.share-none {
    background: #ffebee;
    color: #c62828;
    border-color: #ef9a9a;
}

/* Waiting Room Modal Styles */
.waiting-modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    padding: 20px;
}
.waiting-modal.open {
    display: flex;
}

.waiting-modal-content {
    background: white;
    border-radius: 16px;
    max-width: 450px;
    width: 100%;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    animation: modalSlideIn 0.3s ease;
}

@keyframes modalSlideIn {
    from {
        opacity: 0;
        transform: translateY(-30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.waiting-modal-header {
    text-align: center;
    padding: 24px 24px 16px;
    border-bottom: 1px solid #f0f0f0;
}

.waiting-modal-icon {
    width: 60px;
    height: 60px;
    background: linear-gradient(135deg, #9c27b0, #7b1fa2);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 16px;
    box-shadow: 0 4px 15px rgba(156, 39, 176, 0.3);
}

.waiting-modal-icon i {
    font-size: 28px;
    color: white;
}

.waiting-modal-header h3 {
    margin: 0 0 8px;
    font-size: 18px;
    color: #333;
}

.waiting-modal-header p {
    margin: 0;
    color: #666;
    font-size: 14px;
}

.waiting-modal-header strong {
    color: #9c27b0;
}

.waiting-modal-body {
    padding: 20px 24px;
}

.waiting-form-group {
    margin-bottom: 16px;
}

.waiting-form-group:last-child {
    margin-bottom: 0;
}

.waiting-form-group label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: #555;
    margin-bottom: 8px;
}

.waiting-form-group label i {
    margin-left: 6px;
    color: #888;
}

.waiting-form-group select,
.waiting-form-group textarea {
    width: 100%;
    padding: 10px 12px;
    border: 1.5px solid #e0e0e0;
    border-radius: 8px;
    font-size: 14px;
    font-family: inherit;
    transition: all 0.2s;
}

.waiting-form-group select:focus,
.waiting-form-group textarea:focus {
    border-color: #9c27b0;
    outline: none;
    box-shadow: 0 0 0 3px rgba(156, 39, 176, 0.1);
}

.priority-options {
    display: flex;
    gap: 10px;
}

.priority-option {
    flex: 1;
    cursor: pointer;
}

.priority-option input {
    display: none;
}

.priority-label {
    display: block;
    padding: 10px;
    text-align: center;
    border: 2px solid #e0e0e0;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    transition: all 0.2s;
}

.priority-label.normal {
    color: #4caf50;
}

.priority-label.important {
    color: #ff9800;
}

.priority-label.urgent {
    color: #f44336;
}

.priority-option input:checked + .priority-label.normal {
    background: #e8f5e9;
    border-color: #4caf50;
}

.priority-option input:checked + .priority-label.important {
    background: #fff3e0;
    border-color: #ff9800;
}

.priority-option input:checked + .priority-label.urgent {
    background: #ffebee;
    border-color: #f44336;
}

.waiting-modal-footer {
    display: flex;
    gap: 12px;
    padding: 16px 24px 24px;
    border-top: 1px solid #f0f0f0;
}

.waiting-modal-footer button {
    flex: 1;
    padding: 12px 16px;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: all 0.2s;
    font-family: inherit;
}

.btn-skip {
    background: #f5f5f5;
    color: #666;
}

.btn-skip:hover {
    background: #e0e0e0;
}

.btn-add-waiting {
    background: linear-gradient(135deg, #9c27b0, #7b1fa2);
    color: white;
    box-shadow: 0 3px 10px rgba(156, 39, 176, 0.3);
}

.btn-add-waiting:hover {
    background: linear-gradient(135deg, #7b1fa2, #6a1b9a);
}

@media (max-width: 500px) {
    .waiting-modal-footer {
        flex-direction: column;
    }
    .priority-options {
        flex-direction: column;
    }
}
</style>

<?php if (isset($_GET['deleted'])): ?>
<div class="alert alert-success" data-auto-hide>
    <i class="fas fa-check-circle alert-icon"></i>
    سەردانەکە بە سەرکەوتوویی سڕایەوە
</div>
<?php endif; ?>

<?php if (isset($_GET['success'])): ?>
<div class="alert alert-success" data-auto-hide>
    <i class="fas fa-check-circle alert-icon"></i>
    سەردانەکە بە سەرکەوتوویی تۆمار کرا
</div>
<?php endif; ?>

<?php if (isset($_GET['updated'])): ?>
<div class="alert alert-success" data-auto-hide>
    <i class="fas fa-check-circle alert-icon"></i>
    سەردانەکە بە سەرکەوتوویی نوێکرایەوە
</div>
<?php endif; ?>

<div class="visits-container">
    <!-- Page Header -->
    <div class="page-header">
        <div class="page-title">
            <div class="icon"><i class="fas fa-calendar-check"></i></div>
            <div>
                <h2>سەردانەکان</h2>
                <small style="color: #888;">بەڕێوەبردنی سەردانی نەخۆشەکان</small>
            </div>
        </div>
        <div class="page-actions">
            <a href="<?= APP_URL ?>/visits-report.php" class="btn-add" style="background: linear-gradient(135deg, #7c3aed, #5b21b6); border-color: #7c3aed;">
                <i class="fas fa-chart-line"></i>
                ڕاپۆرت
            </a>
            <?php if ($permission->can('page.new_visit')): ?>
            <a href="<?= APP_URL ?>/new_visit.php" data-new-visit-dialog class="btn-add">
                <i class="fas fa-plus"></i>
                سەردانی نوێ
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Filters -->
    <div class="filters-section">
        <form method="GET" action="" id="visits-filter-form">
            <div class="filters-row">
                <div class="filter-group search-fg">
                    <label><i class="fas fa-search"></i> گەڕان:</label>
                    <input type="text" id="visits-search-input" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="ناو یان ژمارەی مۆبایل..." autocomplete="off">
                    <div id="visits-suggestions" class="visits-suggestions-box" style="display:none;"></div>
                    <small id="visits-search-hint" class="visits-search-hint" style="display:none;"><i class="fas fa-globe"></i> گەڕان لە هەموو سەردانەکاندایە — فلتەرەکان ناچالاکن</small>
                </div>

                <div class="filter-group">
                    <label><i class="fas fa-calendar"></i> لە:</label>
                    <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
                </div>

                <div class="filter-group">
                    <label><i class="fas fa-calendar-check"></i> بۆ:</label>
                    <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
                </div>

                <div class="filter-group">
                    <label><i class="fas fa-user-md"></i> دکتۆر:</label>
                <?php if (!$isDoctor): ?>
                    <select name="doctor">
                        <option value="">هەموو</option>
                        <?php foreach ($doctors as $doctor): ?>
                            <option value="<?= $doctor['id'] ?>" <?= $doctor_filter == $doctor['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($doctor['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                </div>

                <div class="filter-group">
                    <label><i class="fas fa-user-tag"></i> بەپێی:</label>
                    <select name="doctor_field">
                        <option value="">هەموو ڕۆڵەکان</option>
                        <option value="assigned" <?= $doctor_field_filter === 'assigned' ? 'selected' : '' ?>>د. بینراو</option>
                        <?php if ($hasVisitDoctorCols): ?>
                        <option value="checkup" <?= $doctor_field_filter === 'checkup' ? 'selected' : '' ?>>د. فەحس</option>
                        <option value="treatment" <?= $doctor_field_filter === 'treatment' ? 'selected' : '' ?>>د. ئیش</option>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label><i class="fas fa-toggle-on"></i> دۆخ:</label>
                    <select name="status">
                        <option value="">هەموو</option>
                        <option value="new" <?= $status_filter === 'new' ? 'selected' : '' ?>>نوێ</option>
                        <option value="old" <?= $status_filter === 'old' ? 'selected' : '' ?>>کۆن</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label><i class="fas fa-notes-medical"></i> جۆری نەخۆش:</label>
                    <select name="visit_type">
                        <option value="">هەموو</option>
                        <option value="checkup" <?= $visit_type_filter === 'checkup' ? 'selected' : '' ?>>پشکنین</option>
                        <option value="treatment" <?= $visit_type_filter === 'treatment' ? 'selected' : '' ?>>ئیش</option>
                    </select>
                </div>

                <button type="submit" class="btn-filter">
                    <i class="fas fa-filter"></i>
                    فلتەر
                </button>
                <a href="<?= APP_URL ?>/visits.php" class="btn-reset">
                    <i class="fas fa-undo"></i>
                    ڕیسێت
                </a>
            </div>

            <!-- Quick ranges — plain links so they survive a reload/bookmark -->
            <div class="range-row">
                <?php
                $ranges = [
                    'today'     => ['ئەمڕۆ', 'fa-calendar-day'],
                    'yesterday' => ['دوێنێ', 'fa-calendar-minus'],
                    'week'      => ['ئەم هەفتەیە', 'fa-calendar-week'],
                    'month'     => ['ئەم مانگە', 'fa-calendar-alt'],
                ];
                foreach ($ranges as $key => [$label, $icon]):
                ?>
                    <a href="<?= APP_URL ?>/visits.php?range=<?= $key ?>"
                       class="range-chip <?= $range_filter === $key ? 'is-active' : '' ?>">
                        <i class="fas <?= $icon ?>"></i> <?= $label ?>
                    </a>
                <?php endforeach; ?>
                <?php if ($range_filter !== ''): ?>
                    <span class="range-dates">
                        <i class="fas fa-arrow-left"></i>
                        <?= htmlspecialchars($range_from) ?><?= $range_to !== $range_from ? ' — ' . htmlspecialchars($range_to) : '' ?>
                    </span>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Summary of whatever is currently filtered -->
    <?php if (!empty($summary_stats)): ?>
    <div class="summary-strip">
        <div class="summary-item summary-total">
            <span class="summary-value" data-summary="total_visits"><?= (int)$summary_stats['total_visits'] ?></span>
            <span class="summary-label">کۆی سەردان</span>
        </div>
        <div class="summary-item">
            <span class="summary-value" data-summary="unique_patients"><?= (int)$summary_stats['unique_patients'] ?></span>
            <span class="summary-label">نەخۆش</span>
        </div>
        <div class="summary-sep"></div>
        <div class="summary-item summary-checkup">
            <span class="summary-value" data-summary="checkup_visits"><?= (int)$summary_stats['checkup_visits'] ?></span>
            <span class="summary-label">پشکنین</span>
        </div>
        <div class="summary-item summary-treatment">
            <span class="summary-value" data-summary="treatment_visits"><?= (int)$summary_stats['treatment_visits'] ?></span>
            <span class="summary-label">ئیش</span>
        </div>
        <div class="summary-sep"></div>
        <div class="summary-item summary-new">
            <span class="summary-value" data-summary="new_patients"><?= (int)$summary_stats['new_patients'] ?></span>
            <span class="summary-label">نوێ</span>
        </div>
        <div class="summary-item summary-old">
            <span class="summary-value" data-summary="old_patients"><?= (int)$summary_stats['old_patients'] ?></span>
            <span class="summary-label">کۆن</span>
        </div>
        <div class="summary-item summary-treated">
            <span class="summary-value" data-summary="treated_visits"><?= (int)$summary_stats['treated_visits'] ?></span>
            <span class="summary-label">چارەسەرکراو</span>
        </div>
        <button type="button" onclick="window.print()" class="summary-print" title="چاپکردن">
            <i class="fas fa-print"></i>
        </button>
    </div>
    <?php endif; ?>

    <!-- Doctor's Report -->
    <?php if (!empty($doctors_report)): ?>
    <div class="report-section">
        <div class="report-header">
            <div class="report-title">
                <i class="fas fa-user-md"></i>
                ڕاپۆرتی دکتۆرەکان - <?= date('d-m-Y') ?>
            </div>
        </div>
        <div style="overflow-x: auto;">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>دکتۆر</th>
                        <th>کۆی گشتی</th>
                        <th>داواکراو</th>
                        <th>بینراو</th>
                        <th>نوێ</th>
                        <th>کۆن</th>
                        <th>نیشەکان</th>
                        <th>سەرچاوەکان</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($doctors_report as $doctor_stat):
                        $doctor_id = $doctor_stat['doctor_id'];
                    ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($doctor_stat['doctor_name']) ?></strong></td>
                        <td style="font-weight: 600; color: #2196f3;"><?= $doctor_stat['total_patients'] ?></td>
                        <td style="font-weight: 600; color: #5856d6;"><?= $doctor_stat['requested_patients'] ?></td>
                        <td style="font-weight: 600; color: #4caf50;"><?= $doctor_stat['assigned_patients'] ?></td>
                        <td style="font-weight: 600; color: #f44336;"><?= $doctor_stat['new_patients'] ?></td>
                        <td style="font-weight: 600; color: #ff9800;"><?= $doctor_stat['old_patients'] ?></td>
                        <td>
                            <?php if (!empty($procedure_breakdown[$doctor_id])): ?>
                            <div class="chip-group">
                                <?php foreach ($procedure_breakdown[$doctor_id] as $proc): ?>
                                <span class="chip">
                                    <?= htmlspecialchars($proc['procedure_treatment']) ?>
                                    <span class="chip-count"><?= $proc['count'] ?></span>
                                </span>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <span style="color: #999;">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($source_breakdown[$doctor_id])): ?>
                            <div class="chip-group">
                                <?php foreach ($source_breakdown[$doctor_id] as $source): ?>
                                <span class="chip">
                                    <?= htmlspecialchars($source['referral_source']) ?>
                                    <span class="chip-count"><?= $source['count'] ?></span>
                                </span>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <span style="color: #999;">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Visits Table -->
    <div class="visits-table-section">
        <div class="table-header">
            <div class="table-title">
                <i class="fas fa-list"></i>
                لیستی سەردانەکان
                <span class="table-count" id="visits-count"><?= $totalVisits ?></span>
            </div>
            <div class="table-actions">
                <button onclick="window.print()" class="btn-print">
                    <i class="fas fa-print"></i>
                    چاپکردن
                </button>
            </div>
        </div>

        <div id="visits-results">
        <div class="visits-table-scroll">
            <?php if (empty($visits)): ?>
            <div class="empty-state">
                <i class="fas fa-calendar-times"></i>
                <h3>هیچ سەردانێک نەدۆزرایەوە</h3>
                <p>هیچ سەردانێک لەگەڵ ئەم فلتەرانە نییە</p>
            </div>
            <?php else: ?>
            <table class="visits-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>نەخۆش</th>
                        <th>بەروار</th>
                        <th>نرخ</th>
                        <th>چارەسەرکرا</th>
                        <th>جۆری نەخۆش</th>
                        <th>د. داواکراو</th>
                        <th>د. بینراو</th>
                        <th>د. فەحس / ئیش</th>
                        <th>کاتی هاتن</th>
                        <th>کاتی چوونەژوورەوە</th>
                        <th>ئیش</th>
                        <th>دۆخ</th>
                        <th>سەرچاوە</th>
                        <th>مەوعید</th>
                        <th>کردارەکان</th>
                    </tr>
                </thead>
                <tbody id="visits-tbody">
                    <?php
                    $rowNum = $offset + 1;
                    foreach ($visits as $visit):
                    ?>
                    <?php
                    $isLinked       = !empty($visit['linked_patient_id']);
                    $hasPhoneMatch  = !$isLinked && !empty($visit['phone_match_patient_id']);
                    if ($isLinked) {
                        $shareClass = 'share-linked';
                        $shareIcon  = 'fa-check-circle';
                        $shareText  = 'بەشدارکراوە';
                    } elseif ($hasPhoneMatch) {
                        $shareClass = 'share-match';
                        $shareIcon  = 'fa-exclamation-circle';
                        $shareText  = 'هاوشێوە هەیە';
                    } else {
                        $shareClass = 'share-none';
                        $shareIcon  = 'fa-times-circle';
                        $shareText  = 'بەشدار نەکراوە';
                    }
                    ?>
                    <tr>
                        <td><?= $rowNum++ ?></td>
                        <td>
                            <div class="patient-info">
                                <div class="patient-avatar">
                                    <?= mb_substr($visit['patient_name'], 0, 1, 'UTF-8') ?>
                                </div>
                                <div class="patient-details">
                                    <div class="patient-name"><?= htmlspecialchars($visit['patient_name']) ?></div>
                                    <div class="patient-phone">
                                        <i class="fas fa-phone"></i>
                                        <?= htmlspecialchars($visit['mobile_number']) ?>
                                    </div>
                                    <span class="share-badge <?= $shareClass ?>" title="<?= $shareText ?>">
                                        <i class="fas <?= $shareIcon ?>"></i> <?= $shareText ?>
                                    </span>
                                </div>
                            </div>
                        </td>
                        <td><?= formatVisitDate($visit['visit_date']) ?></td>
                        <td><span class="price-cell"><?= htmlspecialchars(formatVisitPrice($visit['price'] ?? 0, $visit['visit_type'] ?? 'checkup')) ?></span></td>
                        <td>
                            <?php $treated = $visit['treated'] ?? 'no'; ?>
                            <span class="status-badge <?= $treated === 'yes' ? 'treated-yes' : 'treated-no' ?>">
                                <?= $treated === 'yes' ? 'بەڵێ' : 'نەخێر' ?>
                            </span>
                        </td>
                        <td>
                            <?php $visitType = $visit['visit_type'] ?? 'checkup'; ?>
                            <span class="status-badge <?= $visitType === 'treatment' ? 'visit-treatment' : 'visit-checkup' ?>">
                                <?= $visitType === 'treatment' ? 'ئیش' : 'پشکنین' ?>
                            </span>
                        </td>
                        <td>
                            <span class="doctor-name"><?= htmlspecialchars($visit['requested_doctor_name'] ?: '-') ?></span>
                        </td>
                        <td>
                            <span class="doctor-name"><?= htmlspecialchars($visit['assigned_doctor_name'] ?: '-') ?></span>
                        </td>
                        <td>
                            <?php
                            $docChips = '';
                            if (!empty($visit['checkup_doctor_name'])) {
                                $docChips .= '<span class="doc-chip doc-chip-checkup"><i class="fas fa-stethoscope"></i> ' . htmlspecialchars($visit['checkup_doctor_name']) . '</span>';
                            }
                            if (!empty($visit['treatment_doctor_name'])) {
                                $docChips .= '<span class="doc-chip doc-chip-treatment"><i class="fas fa-briefcase-medical"></i> ' . htmlspecialchars($visit['treatment_doctor_name']) . '</span>';
                            }
                            ?>
                            <?= $docChips !== '' ? '<div class="doc-chips">' . $docChips . '</div>' : '<span class="doctor-name">-</span>' ?>
                        </td>
                        <td>
                            <span class="time-cell"><?= formatVisitTime($visit['arrival_time']) ?></span>
                        </td>
                        <td>
                            <span class="time-cell"><?= formatVisitTime($visit['checkin_time']) ?></span>
                        </td>
                        <td>
                            <span class="procedure-cell" title="<?= htmlspecialchars($visit['procedure_treatment'] ?: '') ?>">
                                <?= htmlspecialchars($visit['procedure_treatment'] ?: '-') ?>
                            </span>
                        </td>
                        <td>
                            <span class="status-badge <?= $visit['patient_status'] === 'new' ? 'new' : 'old' ?>">
                                <?= $visit['patient_status'] === 'new' ? 'نوێ' : 'کۆن' ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($visit['referral_source'] ?: '-') ?></td>
                        <td>
                            <?php
                            if (!empty($visit['upcoming_apt'])) {
                                $parts = explode('|', $visit['upcoming_apt']);
                                $aptDate   = $parts[0] ?? '';
                                $aptTime   = $parts[1] ?? '';
                                $aptStatus = $parts[2] ?? '';
                                $aptDoctor = $parts[3] ?? '';
                                $statusClrMap = [
                                    'confirmed'   => '#0078d4',
                                    'waiting'     => '#f57c00',
                                    'in_progress' => '#2196f3',
                                    'rescheduled' => '#ff9800',
                                ];
                                $statusClr = $statusClrMap[$aptStatus] ?? '#16a34a';
                            ?>
                                <span style="display:inline-flex;flex-direction:column;gap:2px;padding:4px 8px;background:<?= $statusClr ?>1a;color:<?= $statusClr ?>;border:1px solid <?= $statusClr ?>66;border-radius:8px;font-size:11px;font-weight:600;">
                                    <span><i class="fas fa-calendar-check"></i> <?= htmlspecialchars($aptDate) ?></span>
                                    <span style="font-size:10px;opacity:.9;"><i class="fas fa-clock"></i> <?= htmlspecialchars(substr($aptTime, 0, 5)) ?><?= $aptDoctor ? ' · د. ' . htmlspecialchars($aptDoctor) : '' ?></span>
                                </span>
                            <?php } else { ?>
                                <span style="display:inline-flex;align-items:center;gap:4px;padding:3px 8px;background:#f1f5f9;color:#94a3b8;border:1px dashed #cbd5e1;border-radius:8px;font-size:11px;">
                                    <i class="fas fa-calendar-times"></i> بێ مەوعید
                                </span>
                            <?php } ?>
                        </td>
                        <td>
                            <div class="action-buttons">
                                <?php if ($isLinked): ?>
                                    <a href="<?= APP_URL ?>/patients/view.php?id=<?= (int)$visit['linked_patient_id'] ?>" class="btn-action btn-view-patient" title="بینین لە نەخۆشەکان">
                                        <i class="fas fa-id-card"></i>
                                    </a>
                                <?php else: ?>
                                    <button class="btn-action btn-sync-patient<?= $hasPhoneMatch ? ' has-match' : '' ?>"
                                            onclick="openSyncPatientModal(<?= (int)$visit['id'] ?>, '<?= addslashes(htmlspecialchars($visit['patient_name'])) ?>', '<?= addslashes(htmlspecialchars($visit['mobile_number'])) ?>', <?= $hasPhoneMatch ? 'true' : 'false' ?>)"
                                            title="<?= $hasPhoneMatch ? 'پەیوەستکردن بە نەخۆشێکی هەبوو' : 'زیادکردن بۆ نەخۆشەکان' ?>">
                                        <i class="fas fa-user-plus"></i>
                                    </button>
                                <?php endif; ?>
                                <button class="btn-action btn-to-waiting" onclick="openWaitingModal(<?= (int)($visit['patient_id'] ?? 0) ?>, '<?= addslashes(htmlspecialchars($visit['patient_name'])) ?>', '<?= addslashes(htmlspecialchars($visit['visit_type'] ?? '')) ?>', <?= !empty($visit['work_type_id']) ? (int)$visit['work_type_id'] : 'null' ?>, <?= (int)$visit['id'] ?>)" title="ناردن بۆ ژووری چاوەڕوانی">
                                    <i class="fas fa-user-clock"></i>
                                </button>
                                <button class="btn-action btn-waiting" onclick="openAppointmentForPatient(<?= (int)($visit['patient_id'] ?? 0) ?>, '<?= addslashes(htmlspecialchars($visit['patient_name'])) ?>', '<?= addslashes(htmlspecialchars($visit['mobile_number'])) ?>')" title="مەوعیدێکی نوێ">
                                    <i class="fas fa-calendar-plus"></i>
                                </button>
                                <?php if ($hasFullAccess): ?>
                                <a href="<?= APP_URL ?>/edit_visit.php?id=<?= $visit['id'] ?>" class="btn-action btn-edit" title="دەستکاری">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <button class="btn-action btn-delete" onclick="confirmDeleteVisit(<?= $visit['id'] ?>)" title="سڕینەوە">
                                    <i class="fas fa-trash"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        </div><!-- /#visits-results -->

        <!-- Pagination (outside #visits-results so AJAX renders don't destroy it) -->
        <div id="visits-pagination" class="pagination-section" style="<?= $totalPages > 1 ? '' : 'display:none;' ?>">
            <?php if ($totalPages > 1): ?>
            <div class="pagination-info">
                پیشاندانی <strong><?= $offset + 1 ?></strong> - <strong><?= min($offset + $perPage, $totalVisits) ?></strong> لە <strong><?= $totalVisits ?></strong> سەردان
            </div>
            <div class="pagination-controls">
                <a href="<?= buildPaginationUrl(1) ?>" class="pagination-btn <?= $page <= 1 ? 'disabled' : '' ?>"><i class="fas fa-angle-double-right"></i></a>
                <a href="<?= buildPaginationUrl($page - 1) ?>" class="pagination-btn <?= $page <= 1 ? 'disabled' : '' ?>"><i class="fas fa-angle-right"></i></a>
                <?php
                $range = 2;
                $startPage = max(1, $page - $range);
                $endPage = min($totalPages, $page + $range);
                if ($startPage > 1): ?>
                    <a href="<?= buildPaginationUrl(1) ?>" class="pagination-btn">1</a>
                    <?php if ($startPage > 2): ?><span class="pagination-ellipsis">...</span><?php endif; ?>
                <?php endif; ?>
                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <a href="<?= buildPaginationUrl($i) ?>" class="pagination-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
                <?php endfor; ?>
                <?php if ($endPage < $totalPages): ?>
                    <?php if ($endPage < $totalPages - 1): ?><span class="pagination-ellipsis">...</span><?php endif; ?>
                    <a href="<?= buildPaginationUrl($totalPages) ?>" class="pagination-btn"><?= $totalPages ?></a>
                <?php endif; ?>
                <a href="<?= buildPaginationUrl($page + 1) ?>" class="pagination-btn <?= $page >= $totalPages ? 'disabled' : '' ?>"><i class="fas fa-angle-left"></i></a>
                <a href="<?= buildPaginationUrl($totalPages) ?>" class="pagination-btn <?= $page >= $totalPages ? 'disabled' : '' ?>"><i class="fas fa-angle-double-left"></i></a>
            </div>
            <?php endif; ?>
        </div>

        <!-- Load more / Infinite scroll sentinel -->
        <div id="visits-load-section">
            <div id="visits-scroll-sentinel" style="height:1px;"></div>
            <div id="visits-spinner" style="display:none;">
                <div class="spinner-ring"></div>
                <span>بارکردن...</span>
            </div>
            <button id="btn-load-more" style="display:<?= $totalPages > 1 ? 'inline-block' : 'none' ?>;">
                <i class="fas fa-chevron-down"></i> زیاتر بارکە
            </button>
            <p id="visits-end-msg" style="display:none;">
                <i class="fas fa-check-circle"></i> هەمووی نیشاندرا
            </p>
        </div>
    </div>
</div>

<!-- Sync Visit → Patients Modal -->
<div id="syncPatientModal" class="waiting-modal">
    <div class="waiting-modal-content">
        <div class="waiting-modal-header">
            <div class="waiting-modal-icon" style="background: linear-gradient(135deg, #f44336, #c62828); box-shadow: 0 4px 15px rgba(244,67,54,.3);">
                <i class="fas fa-user-plus"></i>
            </div>
            <h3 id="sync_modal_title">زیادکردن بۆ نەخۆشەکان</h3>
            <p>نەخۆش: <strong id="sync_patient_name"></strong></p>
            <p style="font-size: 12px; color: #888;">
                <i class="fas fa-phone"></i> <span id="sync_patient_phone"></span>
            </p>
            <p id="sync_match_hint" style="display:none; font-size: 12px; color: #ef6c00; margin-top: 8px;">
                <i class="fas fa-exclamation-circle"></i> ژمارەی مۆبایل لەگەڵ نەخۆشێکی تۆمارکراو یەکدەگرێتەوە — پەیوەست دەکرێت بەو نەخۆشە
            </p>
        </div>

        <div class="waiting-modal-body" id="sync_gender_section">
            <div class="waiting-form-group">
                <label><i class="fas fa-venus-mars"></i> ڕەگەز</label>
                <div class="priority-options">
                    <label class="priority-option">
                        <input type="radio" name="sync_gender" value="male" checked>
                        <span class="priority-label normal" style="color:#1976d2;">نێر</span>
                    </label>
                    <label class="priority-option">
                        <input type="radio" name="sync_gender" value="female">
                        <span class="priority-label important" style="color:#e91e63;">مێ</span>
                    </label>
                </div>
            </div>
        </div>

        <div class="waiting-modal-footer">
            <button type="button" class="btn-skip" onclick="closeSyncPatientModal()">
                <i class="fas fa-times"></i> هەڵوەشاندنەوە
            </button>
            <button type="button" class="btn-add-waiting" id="sync_submit_btn" onclick="submitSyncPatient()" style="background: linear-gradient(135deg, #f44336, #c62828);">
                <i class="fas fa-check"></i> <span id="sync_submit_label">زیادکردن</span>
            </button>
        </div>
    </div>
</div>

<!-- Send to Waiting Room Modal -->
<div id="waitingRoomModal" class="waiting-modal">
    <form class="waiting-modal-content" method="POST" action="<?= APP_URL ?>/waiting-room.php" onsubmit="return submitWaitingRoom();">
        <input type="hidden" name="action" value="add_walk_in">
        <input type="hidden" name="patient_id" id="wr_patient_id">
        <input type="hidden" name="visit_id" id="wr_visit_id">

        <div class="waiting-modal-header">
            <div class="waiting-modal-icon"><i class="fas fa-user-clock"></i></div>
            <h3>ناردن بۆ ژووری چاوەڕوانی</h3>
            <p>نەخۆش: <strong id="wr_patient_name"></strong></p>
        </div>

        <div class="waiting-modal-body">
            <div class="waiting-form-group">
                <label><i class="fas fa-user-md"></i> دکتۆر</label>
                <select name="doctor_id" id="wr_doctor_id" required
                        onchange="if (this.selectedOptions[0] && this.selectedOptions[0].dataset.xray) { document.getElementById('wr_visit_type').value = 'checkup'; }">
                    <option value="">— دکتۆر هەڵبژێرە —</option>
                    <?php foreach ($doctors_for_modal as $doc): ?>
                        <option value="<?= $doc['id'] ?>"><?= htmlspecialchars($doc['name']) ?></option>
                    <?php endforeach; ?>
                    <?php foreach ($xray_accounts_for_modal as $xa): ?>
                        <option value="<?= $xa['id'] ?>" data-xray="1">ئێکسڕەی — <?= htmlspecialchars($xa['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="waiting-form-group">
                <label><i class="fas fa-stethoscope"></i> جۆری نەخۆش</label>
                <select name="visit_type" id="wr_visit_type">
                    <option value="checkup">پشکینین</option>
                    <option value="treatment">ئیش</option>
                </select>
            </div>

            <div class="waiting-form-group">
                <label><i class="fas fa-briefcase-medical"></i> جۆری ئیش</label>
                <select name="work_type_id" id="wr_work_type_id">
                    <option value="">— هەڵبژێرە —</option>
                    <?php foreach ($work_types_for_modal as $wt): ?>
                        <option value="<?= $wt['id'] ?>"><?= htmlspecialchars($wt['work_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="waiting-form-group">
                <label><i class="fas fa-flag"></i> پێشینە</label>
                <div class="priority-options">
                    <label class="priority-option">
                        <input type="radio" name="priority" value="0" checked>
                        <span class="priority-label normal">ئاسایی</span>
                    </label>
                    <label class="priority-option">
                        <input type="radio" name="priority" value="1">
                        <span class="priority-label important">گرنگ</span>
                    </label>
                    <label class="priority-option">
                        <input type="radio" name="priority" value="2">
                        <span class="priority-label urgent">پەلە</span>
                    </label>
                </div>
            </div>

            <div class="waiting-form-group">
                <label><i class="fas fa-sticky-note"></i> تێبینی</label>
                <textarea name="notes" id="wr_notes" rows="2" placeholder="تێبینی..."></textarea>
            </div>
        </div>

        <div class="waiting-modal-footer">
            <button type="button" class="btn-skip" onclick="closeWaitingModal()">
                <i class="fas fa-times"></i> هەڵوەشاندنەوە
            </button>
            <button type="submit" class="btn-add-waiting">
                <i class="fas fa-check"></i> زیادکردن
            </button>
        </div>
    </form>
</div>

<!-- Appointment Modal — same style as new_visit.php / appointments.php -->
<div id="appointmentModal" class="dialog-overlay">
    <div class="dialog apt-dialog-modern">
        <div class="apt-dialog-header">
            <h4><i class="fas fa-calendar-plus"></i> مەوعیدی نوێ بۆ <span id="modal-patient-name"></span></h4>
            <button type="button" class="apt-dialog-close" onclick="closeAppointmentModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="apt-dialog-body">
            <input type="hidden" id="apt_patient_id">

            <div class="apt-form-grid">
                <div class="apt-form-field">
                    <label><i class="fas fa-user-md"></i> دکتۆر <span class="required">*</span></label>
                    <select id="apt_doctor_id" required onchange="refreshAptSlots()">
                        <option value="">هەڵبژاردن...</option>
                        <?php foreach ($doctors_for_modal as $doc): ?>
                            <option value="<?= $doc['id'] ?>"><?= htmlspecialchars($doc['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="apt-form-field">
                    <label><i class="fas fa-calendar"></i> بەروار <span class="required">*</span></label>
                    <input type="date" id="apt_date" value="<?= date('Y-m-d') ?>" required onchange="refreshAptSlots()">
                </div>
                <div class="apt-form-field">
                    <label><i class="fas fa-hourglass-half"></i> ماوە</label>
                    <select id="apt_duration" onchange="refreshAptSlots()">
                        <?= dh_duration_options_visits(30) ?>
                    </select>
                </div>
            </div>

            <div class="apt-form-grid" style="margin-top: 10px;">
                <div class="apt-form-field">
                    <label><i class="fas fa-tag"></i> جۆر</label>
                    <select id="apt_type">
                        <option value="general">گشتی</option>
                        <option value="checkup">پشکنین</option>
                        <option value="treatment">چارەسەر</option>
                    </select>
                </div>
                <div class="apt-form-field apt-field-2col">
                    <label><i class="fas fa-briefcase-medical"></i> ئیش</label>
                    <select id="apt_work_type">
                        <option value="">— هەڵبژێرە —</option>
                        <?php foreach ($work_types_for_modal as $wt): ?>
                            <option value="<?= $wt['id'] ?>"><?= htmlspecialchars($wt['work_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="existing-bookings-section" id="apt_existing_section" style="display: none;">
                <label><i class="fas fa-calendar-check"></i> گیراوەکان</label>
                <div id="apt_existing_list" class="existing-bookings-list"></div>
            </div>

            <div class="apt-time-section">
                <label><i class="fas fa-clock"></i> کات <span class="required">*</span></label>
                <input type="hidden" id="apt_end_time">
                <div id="apt_time_grid" class="apt-time-grid-container">
                    <div class="apt-time-loading"><i class="fas fa-spinner fa-spin"></i></div>
                </div>
                <div class="apt-time-legend">
                    <span class="legend-available"><i class="fas fa-circle"></i> بەردەست</span>
                    <span class="legend-busy"><i class="fas fa-circle"></i> گیراو</span>
                    <span class="legend-break"><i class="fas fa-circle"></i> پشوو</span>
                </div>
            </div>

            <div class="apt-form-field" style="margin-top: 10px;">
                <label><i class="fas fa-sticky-note"></i> تێبینی</label>
                <input type="text" id="apt_notes" placeholder="تێبینی..." style="width: 100%; padding: 8px 10px; border: 1.5px solid #e0e0e0; border-radius: 6px; font-size: 12px; background: #fafafa;">
            </div>
        </div>

        <div class="apt-dialog-footer">
            <button type="button" class="apt-btn-primary" id="apt_submit_btn" onclick="submitAppointment()">
                <i class="fas fa-check"></i> دروستکردن
            </button>
            <button type="button" class="apt-btn-secondary" onclick="closeAppointmentModal()">
                <i class="fas fa-times"></i> هەڵوەشاندنەوە
            </button>
        </div>
    </div>
</div>

<style>
/* Appointment dialog styles (mirrors appointments.php) */
.dialog-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.55); z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 16px; opacity: 0; visibility: hidden; transition: opacity .2s, visibility .2s; }
.dialog-overlay.open { opacity: 1; visibility: visible; }
.dialog.apt-dialog-modern { max-width: 580px; width: 96%; border-radius: 12px; overflow: hidden; background: #fff; max-height: 92vh; max-height: 92dvh; display: flex; flex-direction: column; box-shadow: 0 20px 60px rgba(0,0,0,.3); transform: scale(.95); transition: transform .2s; }
/* Pass flex layout through a wrapping <form> so the body scrolls and the footer stays visible */
.dialog.apt-dialog-modern > form { display: flex; flex-direction: column; flex: 1; min-height: 0; }
.dialog-overlay.open .dialog.apt-dialog-modern { transform: scale(1); }
.apt-dialog-header { background: linear-gradient(135deg, var(--win-accent, #0078d4) 0%, var(--win-accent-deep, #0063b1) 100%); color: #fff; padding: 12px 16px; display: flex; justify-content: space-between; align-items: center; }
.apt-dialog-header h4 { margin: 0; font-size: 15px; font-weight: 600; display: flex; align-items: center; gap: 8px; }
.apt-dialog-close { background: rgba(255,255,255,.15); border: none; color: #fff; width: 28px; height: 28px; border-radius: 6px; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 12px; }
.apt-dialog-close:hover { background: rgba(255,255,255,.25); }
.apt-dialog-body { padding: 14px 16px; overflow-y: auto; flex: 1; }
.apt-form-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
.apt-form-field { display: flex; flex-direction: column; gap: 4px; }
.apt-form-field.apt-field-2col { grid-column: span 2; }
.apt-form-field label { font-size: 11px; font-weight: 600; color: #546e7a; display: flex; align-items: center; gap: 5px; }
.apt-form-field label i { color: var(--win-accent, #0078d4); font-size: 10px; }
.apt-form-field label .required { color: #f44336; font-size: 10px; }
.apt-form-field input, .apt-form-field select { padding: 8px 10px; border: 1.5px solid #e0e0e0; border-radius: 6px; font-size: 12px; background: #fafafa; }
.apt-form-field input:focus, .apt-form-field select:focus { border-color: var(--win-accent, #0078d4); outline: none; background: #fff; }
.apt-time-section { margin-top: 16px; padding-top: 16px; border-top: 1px solid #f0f0f0; }
.apt-time-section > label { font-size: 11px; font-weight: 600; color: #546e7a; display: flex; align-items: center; gap: 5px; margin-bottom: 6px; }
.apt-time-section > label i { color: var(--win-accent, #0078d4); font-size: 10px; }
.apt-time-section > label .required { color: #f44336; font-size: 10px; }
.apt-time-grid-container { background: #f8f9fa; border: 1px solid #e0e0e0; border-radius: 6px; padding: 8px; max-height: 200px; overflow-y: auto; }
.apt-time-loading { text-align: center; padding: 15px; color: #757575; font-size: 12px; }
.smart-time-picker-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(65px, 1fr)); gap: 6px; padding: 4px; }
.smart-time-slot { padding: 8px 4px; border: 1.5px solid #e0e0e0; border-radius: 6px; text-align: center; cursor: pointer; font-size: 12px; font-weight: 600; background: #fff; color: #37474f; transition: all .2s; }
.smart-time-slot:hover:not(.busy):not(.break) { border-color: var(--win-accent, #0078d4); background: #e3f2fd; color: var(--win-accent, #0078d4); transform: translateY(-1px); }
.smart-time-slot.selected { background: var(--win-accent, #0078d4); color: #fff; border-color: var(--win-accent, #0078d4); }
.smart-time-slot.busy { background: #ffebee; border-color: #ffcdd2; color: #c62828; cursor: not-allowed; opacity: .7; }
/* Booked slots stay selectable (overlapping bookings allowed) */
.smart-time-slot.booked { background: #ffebee; border-color: #ffcdd2; color: #c62828; cursor: pointer; }
.smart-time-slot.booked.selected { background: var(--win-accent, #0078d4); color: #fff; border-color: var(--win-accent, #0078d4); }
.smart-time-slot.break { background: #fff3e0; border-color: #ffe0b2; color: #e65100; cursor: not-allowed; opacity: .7; }
.apt-time-legend { display: flex; gap: 12px; margin-top: 6px; font-size: 10px; color: #78909c; }
.apt-time-legend span { display: flex; align-items: center; gap: 3px; }
.apt-time-legend .legend-available i { color: #4caf50; font-size: 6px; }
.apt-time-legend .legend-busy i { color: #f44336; font-size: 6px; }
.apt-time-legend .legend-break i { color: #ff9800; font-size: 6px; }
.no-working-day { text-align: center; padding: 20px; color: #f44336; background: #ffebee; border-radius: 8px; font-size: 13px; }
.no-working-day i { font-size: 24px; display: block; margin-bottom: 8px; }
.existing-bookings-section { margin: 10px 0; padding: 8px 10px; background: linear-gradient(135deg, #fff8e1, #fffde7); border-radius: 6px; border: 1px solid #ffe082; }
.existing-bookings-section label { display: block; font-weight: 600; font-size: 10px; color: #ef6c00; margin-bottom: 6px; }
.existing-bookings-list { display: flex; flex-wrap: wrap; gap: 6px; }
.existing-booking-item { display: inline-flex; align-items: center; gap: 6px; padding: 5px 10px; background: #fff; border: 1px solid #ffcc80; border-radius: 16px; font-size: 11px; color: #e65100; }
.existing-booking-item i { font-size: 9px; }
.no-bookings-message { padding: 6px; text-align: center; color: #7cb342; font-size: 12px; }
.apt-dialog-footer { padding: 10px 16px; background: #f5f7f9; border-top: 1px solid #e0e0e0; display: flex; gap: 8px; flex-direction: row-reverse; }
.apt-btn-primary, .apt-btn-secondary { padding: 8px 16px; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 5px; border: none; font-family: inherit; }
.apt-btn-primary { background: linear-gradient(135deg, var(--win-accent, #0078d4) 0%, var(--win-accent-deep, #0063b1) 100%); color: #fff; }
.apt-btn-primary:hover { background: linear-gradient(135deg, var(--win-accent-deep, #0063b1) 0%, #004c8c 100%); }
.apt-btn-secondary { background: #fff; color: #546e7a; border: 1.5px solid #e0e0e0; }
.apt-btn-secondary:hover { background: #f5f5f5; }
</style>

<script>
var currentPatientId = null;
var currentPatientName = '';
var currentPatientPhone = '';

function confirmDeleteVisit(id) {
    Swal.fire({
        title: 'دڵنیایت؟',
        text: 'دڵنیایت لە سڕینەوەی ئەم سەردانە؟',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#f44336',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'بەڵێ، بیسڕەوە',
        cancelButtonText: 'نەخێر'
    }).then((result) => {
        if (result.isConfirmed) {
            // POST + CSRF token (injected automatically by header.php)
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = '<?= APP_URL ?>/visits.php';
            var action = document.createElement('input');
            action.type = 'hidden'; action.name = 'action'; action.value = 'delete_visit';
            var visit = document.createElement('input');
            visit.type = 'hidden'; visit.name = 'visit_id'; visit.value = id;
            form.appendChild(action);
            form.appendChild(visit);
            document.body.appendChild(form);
            form.submit();
        }
    });
}

// ── Sync Visit → Patients Modal ──
var syncCurrentVisitId = null;
var syncCurrentHasMatch = false;

function openSyncPatientModal(visitId, patientName, patientPhone, hasMatch) {
    syncCurrentVisitId  = visitId;
    syncCurrentHasMatch = !!hasMatch;
    document.getElementById('sync_patient_name').textContent  = patientName || '';
    document.getElementById('sync_patient_phone').textContent = patientPhone || '';
    document.getElementById('sync_match_hint').style.display  = hasMatch ? 'block' : 'none';
    document.getElementById('sync_gender_section').style.display = hasMatch ? 'none' : 'block';
    document.getElementById('sync_modal_title').textContent = hasMatch ? 'پەیوەستکردن بە نەخۆشێکی هەبوو' : 'زیادکردن بۆ نەخۆشەکان';
    document.getElementById('sync_submit_label').textContent = hasMatch ? 'پەیوەست بکە' : 'زیادکردن';
    var defaultGender = document.querySelector('input[name="sync_gender"][value="male"]');
    if (defaultGender) defaultGender.checked = true;
    document.getElementById('syncPatientModal').classList.add('open');
}

function closeSyncPatientModal() {
    document.getElementById('syncPatientModal').classList.remove('open');
    syncCurrentVisitId = null;
    syncCurrentHasMatch = false;
}

function submitSyncPatient() {
    if (!syncCurrentVisitId) return;
    var btn = document.getElementById('sync_submit_btn');
    var origHTML = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> چاوەڕێبکە...';

    var genderEl = document.querySelector('input[name="sync_gender"]:checked');
    var gender = genderEl ? genderEl.value : 'male';

    var fd = new FormData();
    fd.append('action', 'sync_to_patient');
    fd.append('visit_id', syncCurrentVisitId);
    fd.append('gender', gender);

    fetch('<?= APP_URL ?>/visits.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = origHTML;
            if (data && data.success) {
                closeSyncPatientModal();
                Swal.fire({
                    icon: 'success',
                    title: data.message || 'سەرکەوتوو بوو',
                    timer: 1500,
                    showConfirmButton: false
                }).then(() => window.location.reload());
            } else {
                Swal.fire({ icon: 'error', title: 'هەڵە', text: (data && data.message) || 'هەڵەیەک ڕوویدا', confirmButtonText: 'باشە' });
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = origHTML;
            Swal.fire({ icon: 'error', title: 'هەڵە', text: 'هەڵەی پەیوەندی', confirmButtonText: 'باشە' });
        });
}

// ── Send to Waiting Room Modal ──
function openWaitingModal(patientId, patientName, visitType, workTypeId, visitId) {
    if (!patientId || patientId === 0) {
        Swal.fire({ icon:'warning', title:'نەخۆش نەدۆزرایەوە',
            text:'ئەم سەردانە هیچ نەخۆشێکی پەیوەستی پێوە نییە.',
            confirmButtonText:'باشە' });
        return;
    }
    document.getElementById('wr_patient_id').value = patientId;
    document.getElementById('wr_visit_id').value = visitId || '';
    document.getElementById('wr_patient_name').textContent = patientName || '';
    document.getElementById('wr_notes').value = '';
    document.getElementById('wr_doctor_id').value = '';
    var inheritedVisitType = (visitType === 'checkup' || visitType === 'treatment') ? visitType : 'checkup';
    document.getElementById('wr_visit_type').value = inheritedVisitType;
    var wtSel = document.getElementById('wr_work_type_id');
    wtSel.value = '';
    if (workTypeId) {
        var match = wtSel.querySelector('option[value="' + workTypeId + '"]');
        if (match) wtSel.value = workTypeId;
    }
    var firstPriority = document.querySelector('input[name="priority"][value="0"]');
    if (firstPriority) firstPriority.checked = true;
    document.getElementById('waitingRoomModal').classList.add('open');
}

function closeWaitingModal() {
    document.getElementById('waitingRoomModal').classList.remove('open');
}

function submitWaitingRoom() {
    var doctor = document.getElementById('wr_doctor_id').value;
    if (!doctor) {
        Swal.fire({ icon:'warning', title:'دکتۆر دیاری بکە', confirmButtonText:'باشە' });
        return false;
    }
    return true;
}

// ── Appointment Modal (same logic as new_visit.php) ──
var aptExistingBookings = [];
var aptSelectedTime = '';

function openAppointmentForPatient(patientId, patientName, patientPhone) {
    currentPatientName = patientName || '';
    currentPatientPhone = patientPhone || '';

    // Resolve patient_id (if 0, search by phone)
    if (!patientId || patientId === 0) {
        fetch('<?= APP_URL ?>/ajax/patient_search.php?q=' + encodeURIComponent(patientPhone))
            .then(r => r.json())
            .then(data => {
                if (data.success && data.results && data.results.length > 0) {
                    currentPatientId = data.results[0].id;
                    showAppointmentModal();
                } else {
                    Swal.fire({ icon:'warning', title:'نەخۆش نەدۆزرایەوە',
                        html: 'ئەم سەردانە هیچ نەخۆشێکی پەیوەستی پێوە نییە.<br><small style="color:#888;">تکایە سەرەتا نەخۆش زیاد بکە</small>',
                        confirmButtonText:'باشە' });
                }
            })
            .catch(() => Swal.fire({ icon:'error', title:'هەڵە', text:'هەڵەیەک لە گەڕان بۆ نەخۆش ڕوویدا', confirmButtonText:'باشە' }));
    } else {
        currentPatientId = patientId;
        showAppointmentModal();
    }
}

function showAppointmentModal() {
    document.getElementById('apt_patient_id').value = currentPatientId;
    document.getElementById('modal-patient-name').textContent = currentPatientName;
    document.getElementById('appointmentModal').classList.add('open');
    setTimeout(refreshAptSlots, 150);
}

function closeAppointmentModal() {
    document.getElementById('appointmentModal').classList.remove('open');
    currentPatientId = null;
    currentPatientName = '';
    aptSelectedTime = '';
}

function timeToMin(t) { if (!t) return 0; var p = t.split(':'); return parseInt(p[0])*60 + parseInt(p[1]); }
function addMinToTime(t, mins) {
    if (!t) return '';
    var total = timeToMin(t) + mins;
    var hh = String(Math.floor(total / 60) % 24).padStart(2,'0');
    var mm = String(total % 60).padStart(2,'0');
    return hh + ':' + mm;
}

async function refreshAptSlots() {
    var container    = document.getElementById('apt_time_grid');
    var doctorId     = document.getElementById('apt_doctor_id').value;
    var date         = document.getElementById('apt_date').value;
    var duration     = document.getElementById('apt_duration').value || 30;
    var existingSec  = document.getElementById('apt_existing_section');
    var existingList = document.getElementById('apt_existing_list');

    if (!container) return;
    aptSelectedTime = '';

    if (!doctorId || !date) {
        container.innerHTML = '<div class="no-working-day"><i class="fas fa-exclamation-circle"></i> تکایە دکتۆر و بەروار هەڵبژێرە</div>';
        if (existingSec) existingSec.style.display = 'none';
        return;
    }

    container.innerHTML = '<div class="apt-time-loading"><i class="fas fa-spinner fa-spin"></i> چاوەڕوان بە...</div>';

    try {
        var apiBase = '<?= APP_URL ?>/api/schedule.php';
        var [slotsRes, bookingsRes] = await Promise.all([
            fetch(apiBase + '?action=get_all_slots&doctor_id=' + doctorId + '&date=' + date + '&duration=' + duration),
            fetch(apiBase + '?action=get_bookings&doctor_id=' + doctorId + '&date=' + date)
        ]);
        var slotsResult    = await slotsRes.json();
        var bookingsResult = await bookingsRes.json();

        aptExistingBookings = (bookingsResult.success && Array.isArray(bookingsResult.data)) ? bookingsResult.data : [];

        if (existingSec && existingList) {
            if (aptExistingBookings.length > 0) {
                existingSec.style.display = 'block';
                existingList.innerHTML = aptExistingBookings.map(function(b) {
                    return '<div class="existing-booking-item"><i class="fas fa-clock"></i><span>' + b.start_time + ' - ' + b.end_time + '</span><span class="patient-name">' + (b.patient_name || 'مەوعید') + '</span></div>';
                }).join('');
            } else {
                existingSec.style.display = 'block';
                existingList.innerHTML = '<div class="no-bookings-message"><i class="fas fa-check-circle"></i> هیچ مەوعیدێک نییە</div>';
            }
        }

        if (!slotsResult.success) { container.innerHTML = '<div class="no-working-day"><i class="fas fa-exclamation-circle"></i> ' + (slotsResult.message || 'هەڵەیەک ڕوویدا') + '</div>'; return; }
        var data = slotsResult.data;
        if (!data.working) { container.innerHTML = '<div class="no-working-day"><i class="fas fa-bed"></i> ئەم ڕۆژە ڕۆژی پشووە</div>'; return; }
        if (!data.slots.length) { container.innerHTML = '<div class="no-working-day"><i class="fas fa-calendar-times"></i> هیچ کاتێک بەردەست نییە</div>'; return; }

        var html = '<div class="smart-time-picker-grid">';
        data.slots.forEach(function(slot) {
            var cls = slot.status === 'available' ? '' : slot.status;
            var click = (slot.status === 'available' || slot.status === 'booked') ? 'onclick="selectAptSlot(\'' + slot.time + '\')"' : '';
            html += '<div class="smart-time-slot ' + cls + '" ' + click + '>' + slot.time + '</div>';
        });
        html += '</div>';
        container.innerHTML = html;
    } catch (err) {
        container.innerHTML = '<div class="no-working-day"><i class="fas fa-exclamation-triangle"></i> هەڵەیەک ڕوویدا</div>';
    }
}

function selectAptSlot(time) {
    aptSelectedTime = time;
    var duration = parseInt(document.getElementById('apt_duration').value) || 30;
    document.getElementById('apt_end_time').value = addMinToTime(time, duration);
    document.querySelectorAll('#apt_time_grid .smart-time-slot').forEach(function(s) {
        s.classList.toggle('selected', s.textContent.trim() === time);
    });
}

function submitAppointment() {
    if (!currentPatientId) {
        Swal.fire({ icon:'error', title:'هەڵە', text:'ناسنامەی نەخۆش نەدۆزرایەوە', confirmButtonText:'باشە' });
        return;
    }
    if (!aptSelectedTime) {
        Swal.fire({ icon:'warning', title:'کات هەڵنەبژێردراوە', text:'تکایە کاتێک هەڵبژێرە', confirmButtonText:'باشە' });
        return;
    }
    var doctorId = document.getElementById('apt_doctor_id').value;
    var date     = document.getElementById('apt_date').value;
    var duration = document.getElementById('apt_duration').value || 30;
    if (!doctorId || !date) {
        Swal.fire({ icon:'warning', title:'زانیاری کەم', text:'تکایە دکتۆر و بەروار هەڵبژێرە', confirmButtonText:'باشە' });
        return;
    }

    // Overlapping bookings are allowed by clinic policy — no overlap check

    var fd = new FormData();
    fd.append('patient_id',       currentPatientId);
    fd.append('doctor_id',        doctorId);
    fd.append('appointment_date', date);
    fd.append('appointment_time', aptSelectedTime);
    fd.append('duration',         duration);
    fd.append('type',             document.getElementById('apt_type').value);
    fd.append('work_type_id',     document.getElementById('apt_work_type').value);
    fd.append('notes',            document.getElementById('apt_notes').value);

    var btn = document.getElementById('apt_submit_btn');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> پاشەکەوت دەکرێت...';

    fetch('<?= APP_URL ?>/ajax/create_appointment.php', { method:'POST', body: fd })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            closeAppointmentModal();
            Swal.fire({
                icon: 'success',
                title: 'سەرکەوتوو!',
                text: 'مەوعیدەکە بە سەرکەوتوویی تۆمارکرا',
                showCancelButton: true,
                confirmButtonText: 'بچۆ بۆ مەوعیدەکان',
                cancelButtonText: 'لێرە بمێنەوە',
                confirmButtonColor: 'var(--win-accent, #0078d4)'
            }).then(res => {
                if (res.isConfirmed) window.location.href = '<?= APP_URL ?>/appointments.php?date=' + date + '&created=1';
            });
        } else {
            btn.disabled = false; btn.innerHTML = '<i class="fas fa-check"></i> دروستکردن';
            Swal.fire({ icon:'error', title:'هەڵە!', text: d.message || 'هەڵەیەک ڕوویدا', confirmButtonText:'باشە' });
        }
    })
    .catch(e => {
        btn.disabled = false; btn.innerHTML = '<i class="fas fa-check"></i> دروستکردن';
        Swal.fire({ icon:'error', title:'هەڵە!', text:'کێشەی پەیوەندی: ' + e.message, confirmButtonText:'باشە' });
    });
}

// Close on outside click
document.getElementById('appointmentModal').addEventListener('click', function(e) {
    if (e.target === this) closeAppointmentModal();
});
document.getElementById('waitingRoomModal').addEventListener('click', function(e) {
    if (e.target === this) closeWaitingModal();
});
document.getElementById('syncPatientModal').addEventListener('click', function(e) {
    if (e.target === this) closeSyncPatientModal();
});

// ---------------------------------------------------------------
// ⚡ Live Search · Load More · Infinite Scroll · Real-time Filters
// ---------------------------------------------------------------
(function () {
    const APP_URL       = <?= json_encode(APP_URL) ?>;
    const HAS_FULL_ACCESS = <?= json_encode($hasFullAccess) ?>;

    // DOM refs
    const form        = document.getElementById('visits-filter-form');
    const searchInput = document.getElementById('visits-search-input');
    const resultsDiv  = document.getElementById('visits-results');
    const countBadge  = document.getElementById('visits-count');
    const sugBox      = document.getElementById('visits-suggestions');
    const spinner     = document.getElementById('visits-spinner');
    const loadMoreBtn = document.getElementById('btn-load-more');
    const endMsg      = document.getElementById('visits-end-msg');
    const sentinel    = document.getElementById('visits-scroll-sentinel');

    if (!form || !searchInput || !resultsDiv) return;

    // The summary strip is rendered server-side on first paint, so without this
    // it kept describing the unfiltered page while the table below it changed.
    // Every AJAX response carries the same figures for the same WHERE.
    const summaryStrip = document.querySelector('.summary-strip');
    function applySummary(summary) {
        if (!summary || !summaryStrip) return;
        summaryStrip.querySelectorAll('[data-summary]').forEach(el => {
            const v = summary[el.dataset.summary];
            if (v !== undefined && v !== null) el.textContent = v;
        });
    }

    // State
    const state = {
        page:       <?= (int)$page ?>,
        totalPages: <?= (int)$totalPages ?>,
        total:      <?= (int)$totalVisits ?>,
        loading:    false,
        sugTimer:   null,
        searchTimer:null,
        sugIndex:   -1,   // keyboard nav index
    };

    // ── Utilities ────────────────────────────────────────────
    function esc(s) {
        return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function getFilters(page) {
        const q = searchInput.value.trim();
        // Independent search: a search term searches ALL visits — other filters are not sent
        if (q !== '') {
            return { q: q, page: page || 1 };
        }
        return {
            q:         '',
            // A quick range already resolved to concrete dates server-side and
            // filled both date boxes, so sending those covers it.
            date_from: form.querySelector('[name="date_from"]').value,
            date_to:   form.querySelector('[name="date_to"]').value,
            doctor:    (form.querySelector('[name="doctor"]') || { value: '' }).value,
            doctor_field: (form.querySelector('[name="doctor_field"]') || { value: '' }).value,
            status:    form.querySelector('[name="status"]').value,
            visit_type: form.querySelector('[name="visit_type"]').value,
            page:      page || 1,
        };
    }
    function toQS(obj) {
        return Object.entries(obj).map(([k,v]) => k + '=' + encodeURIComponent(v)).join('&');
    }

    // ── Spinner / loading state ───────────────────────────────
    function showSpinner()  { if (spinner) spinner.style.display = 'flex'; }
    function hideSpinner()  { if (spinner) spinner.style.display = 'none'; }
    // The strip dims with the table so the numbers are visibly mid-refresh
    // rather than looking like a stale answer to the new filter.
    function dimTable()     { resultsDiv.style.opacity = '0.45'; resultsDiv.style.pointerEvents = 'none';
                              if (summaryStrip) summaryStrip.style.opacity = '0.45'; }
    function undimTable()   { resultsDiv.style.opacity = '1';    resultsDiv.style.pointerEvents = '';
                              if (summaryStrip) summaryStrip.style.opacity = '1'; }

    // ── Load-more button & end message ────────────────────────
    function syncLoadMore() {
        if (!loadMoreBtn || !endMsg) return;
        const hasMore = state.page < state.totalPages;
        loadMoreBtn.style.display = hasMore ? 'inline-block' : 'none';
        endMsg.style.display      = (!hasMore && state.total > 0) ? 'block' : 'none';
    }

    // ── Pagination renderer (rebuilt after every AJAX fetch) ──
    const pagDiv = document.getElementById('visits-pagination');
    function pageUrl(p) {
        const f = getFilters(p);
        return '?' + toQS(f);
    }
    function renderPagination(page, totalPages, total, perPage) {
        if (!pagDiv) return;
        if (totalPages <= 1) {
            pagDiv.style.display = 'none';
            pagDiv.innerHTML = '';
            return;
        }
        pagDiv.style.display = '';
        const offset = (page - 1) * perPage;
        const fromN  = offset + 1;
        const toN    = Math.min(offset + perPage, total);

        const range = 2;
        const startP = Math.max(1, page - range);
        const endP   = Math.min(totalPages, page + range);

        let parts = [];
        // first
        parts.push(`<a href="${pageUrl(1)}" data-page="1" class="pagination-btn ${page<=1?'disabled':''}"><i class="fas fa-angle-double-right"></i></a>`);
        // prev
        parts.push(`<a href="${pageUrl(page-1)}" data-page="${page-1}" class="pagination-btn ${page<=1?'disabled':''}"><i class="fas fa-angle-right"></i></a>`);
        // start jump
        if (startP > 1) {
            parts.push(`<a href="${pageUrl(1)}" data-page="1" class="pagination-btn">1</a>`);
            if (startP > 2) parts.push(`<span class="pagination-ellipsis">...</span>`);
        }
        // numbered
        for (let i = startP; i <= endP; i++) {
            parts.push(`<a href="${pageUrl(i)}" data-page="${i}" class="pagination-btn ${i===page?'active':''}">${i}</a>`);
        }
        // end jump
        if (endP < totalPages) {
            if (endP < totalPages - 1) parts.push(`<span class="pagination-ellipsis">...</span>`);
            parts.push(`<a href="${pageUrl(totalPages)}" data-page="${totalPages}" class="pagination-btn">${totalPages}</a>`);
        }
        // next
        parts.push(`<a href="${pageUrl(page+1)}" data-page="${page+1}" class="pagination-btn ${page>=totalPages?'disabled':''}"><i class="fas fa-angle-left"></i></a>`);
        // last
        parts.push(`<a href="${pageUrl(totalPages)}" data-page="${totalPages}" class="pagination-btn ${page>=totalPages?'disabled':''}"><i class="fas fa-angle-double-left"></i></a>`);

        pagDiv.innerHTML = `
            <div class="pagination-info">پیشاندانی <strong>${fromN}</strong> - <strong>${toN}</strong> لە <strong>${total}</strong> سەردان</div>
            <div class="pagination-controls">${parts.join('')}</div>
        `;
    }

    // Intercept pagination clicks → AJAX fetch (no full reload)
    if (pagDiv) {
        pagDiv.addEventListener('click', function(e) {
            const a = e.target.closest('a.pagination-btn');
            if (!a) return;
            if (a.classList.contains('disabled') || a.classList.contains('active')) {
                e.preventDefault(); return;
            }
            const p = parseInt(a.dataset.page || '0', 10);
            if (!p || p < 1) return;
            e.preventDefault();
            fetchVisits(p, false);
            window.scrollTo({ top: resultsDiv.offsetTop - 80, behavior: 'smooth' });
        });
    }

    // د. فەحس / ئیش — merged cell, one thin chip per doctor that exists
    function docChipsCell(v) {
        let chips = '';
        if (v.checkup_doctor_name) {
            chips += `<span class="doc-chip doc-chip-checkup"><i class="fas fa-stethoscope"></i> ${esc(v.checkup_doctor_name)}</span>`;
        }
        if (v.treatment_doctor_name) {
            chips += `<span class="doc-chip doc-chip-treatment"><i class="fas fa-briefcase-medical"></i> ${esc(v.treatment_doctor_name)}</span>`;
        }
        return chips ? `<div class="doc-chips">${chips}</div>` : `<span class="doctor-name">-</span>`;
    }

    // ── Row renderer ─────────────────────────────────────────
    function renderRow(v, rowNum) {
        const avatar  = [...(v.patient_name || '?')][0];
        const treated = v.treated === 'yes';
        const isNew   = v.patient_status === 'new';
        const isTreatmentVisit = v.visit_type === 'treatment';

        let shareCls, shareIcon, shareTxt;
        if (v.is_linked) {
            shareCls = 'share-linked'; shareIcon = 'fa-check-circle'; shareTxt = 'بەشدارکراوە';
        } else if (v.has_phone_match) {
            shareCls = 'share-match';  shareIcon = 'fa-exclamation-circle'; shareTxt = 'هاوشێوە هەیە';
        } else {
            shareCls = 'share-none';   shareIcon = 'fa-times-circle'; shareTxt = 'بەشدار نەکراوە';
        }
        const shareBadge = `<span class="share-badge ${shareCls}" title="${shareTxt}"><i class="fas ${shareIcon}"></i> ${shareTxt}</span>`;

        let actions = '';
        if (v.is_linked) {
            actions += `<a href="${APP_URL}/patients/view.php?id=${v.linked_patient_id}" class="btn-action btn-view-patient" title="بینین لە نەخۆشەکان"><i class="fas fa-id-card"></i></a>`;
        } else {
            const matchCls = v.has_phone_match ? ' has-match' : '';
            const matchTitle = v.has_phone_match ? 'پەیوەستکردن بە نەخۆشێکی هەبوو' : 'زیادکردن بۆ نەخۆشەکان';
            actions += `<button class="btn-action btn-sync-patient${matchCls}" onclick="openSyncPatientModal(${v.id},'${esc(v.patient_name)}','${esc(v.mobile_number)}',${v.has_phone_match ? 'true' : 'false'})" title="${matchTitle}"><i class="fas fa-user-plus"></i></button>`;
        }
        actions += `<button class="btn-action btn-to-waiting" onclick="openWaitingModal(${v.patient_id},'${esc(v.patient_name)}','${esc(v.visit_type || '')}',${v.work_type_id ? parseInt(v.work_type_id, 10) : 'null'},${v.id})" title="ناردن بۆ ژووری چاوەڕوانی"><i class="fas fa-user-clock"></i></button>`;
        actions += `<button class="btn-action btn-waiting" onclick="openAppointmentForPatient(${v.patient_id},'${esc(v.patient_name)}','${esc(v.mobile_number)}')" title="مەوعیدێکی نوێ"><i class="fas fa-calendar-plus"></i></button>`;
        if (HAS_FULL_ACCESS) {
            actions += `<a href="${APP_URL}/edit_visit.php?id=${v.id}" class="btn-action btn-edit"><i class="fas fa-edit"></i></a>`;
            actions += `<button class="btn-action btn-delete" onclick="confirmDeleteVisit(${v.id})"><i class="fas fa-trash"></i></button>`;
        }
        return `<tr>
            <td>${rowNum}</td>
            <td><div class="patient-info"><div class="patient-avatar">${esc(avatar)}</div><div class="patient-details"><div class="patient-name">${esc(v.patient_name)}</div><div class="patient-phone"><i class="fas fa-phone"></i> ${esc(v.mobile_number)}</div>${shareBadge}</div></div></td>
            <td>${esc(v.visit_date_fmt)}</td>
            <td><span class="price-cell">${isTreatmentVisit ? '-' : esc(v.price_fmt || '0.00')}</span></td>
            <td><span class="status-badge ${treated?'treated-yes':'treated-no'}">${treated?'بەڵێ':'نەخێر'}</span></td>
            <td><span class="status-badge ${isTreatmentVisit?'visit-treatment':'visit-checkup'}">${isTreatmentVisit?'ئیش':'پشکنین'}</span></td>
            <td><span class="doctor-name">${esc(v.requested_doctor_name||'-')}</span></td>
            <td><span class="doctor-name">${esc(v.assigned_doctor_name||'-')}</span></td>
            <td>${docChipsCell(v)}</td>
            <td><span class="time-cell">${esc(v.arrival_time_fmt)}</span></td>
            <td><span class="time-cell">${esc(v.checkin_time_fmt)}</span></td>
            <td><span class="procedure-cell" title="${esc(v.procedure_treatment)}">${esc(v.procedure_treatment||'-')}</span></td>
            <td><span class="status-badge ${isNew?'new':'old'}">${isNew?'نوێ':'کۆن'}</span></td>
            <td>${esc(v.referral_source||'-')}</td>
            <td><div class="action-buttons">${actions}</div></td>
        </tr>`;
    }

    const TABLE_HEAD = `<thead><tr>
        <th>#</th><th>نەخۆش</th><th>بەروار</th><th>نرخ</th><th>چارەسەرکرا</th>
        <th>جۆری نەخۆش</th><th>د. داواکراو</th><th>د. بینراو</th><th>د. فەحس / ئیش</th><th>کاتی هاتن</th>
        <th>کاتی چوونەژوورەوە</th><th>ئیش</th><th>دۆخ</th><th>سەرچاوە</th><th>کردارەکان</th>
    </tr></thead>`;

    // ── Full table replace (new search) ──────────────────────
    function renderTable(data) {
        if (countBadge) countBadge.textContent = data.total;
        applySummary(data.summary);
        if (!data.visits || data.visits.length === 0) {
            resultsDiv.innerHTML = `<div class="empty-state"><i class="fas fa-calendar-times"></i><h3>هیچ سەردانێک نەدۆزرایەوە</h3><p>هیچ سەردانێک لەگەڵ ئەم فلتەرانە نییە</p></div>`;
            state.page       = data.page || 1;
            state.totalPages = data.total_pages || 0;
            state.total      = data.total || 0;
            renderPagination(state.page, state.totalPages, state.total, data.per_page || 10);
            syncLoadMore();
            return;
        }
        const offset = (data.page - 1) * data.per_page;
        const rows   = data.visits.map((v, i) => renderRow(v, offset + i + 1)).join('');
        resultsDiv.innerHTML = `<div class="visits-table-scroll"><table class="visits-table">${TABLE_HEAD}<tbody id="visits-tbody">${rows}</tbody></table></div>`;
        state.page       = data.page;
        state.totalPages = data.total_pages;
        state.total      = data.total;
        renderPagination(state.page, state.totalPages, state.total, data.per_page);
        syncLoadMore();
    }

    // ── Append rows (load more / infinite scroll) ─────────────
    function appendRows(data) {
        const tbody = document.getElementById('visits-tbody');
        if (!tbody) { renderTable(data); return; }
        const offset = (data.page - 1) * data.per_page;
        data.visits.forEach((v, i) => {
            tbody.insertAdjacentHTML('beforeend', renderRow(v, offset + i + 1));
        });
        if (countBadge) countBadge.textContent = data.total;
        applySummary(data.summary);
        state.page       = data.page;
        state.totalPages = data.total_pages;
        state.total      = data.total;
        renderPagination(state.page, state.totalPages, state.total, data.per_page);
        syncLoadMore();
    }

    // ── Core fetch ────────────────────────────────────────────
    function fetchVisits(page, append) {
        if (state.loading) return;
        state.loading = true;
        loadMoreBtn && (loadMoreBtn.disabled = true);
        showSpinner();
        if (!append) dimTable();

        const qs = toQS(getFilters(page));
        history.replaceState(null, '', '?' + qs);

        fetch(`${APP_URL}/ajax/ts_visits.php?` + qs, { headers: { Accept: 'application/json' } })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    append ? appendRows(data) : renderTable(data);
                }
            })
            .catch(() => {})
            .finally(() => {
                state.loading = false;
                hideSpinner();
                undimTable();
                loadMoreBtn && (loadMoreBtn.disabled = false);
            });
    }

    // ── Live search suggestions ───────────────────────────────
    function hideSuggestions() {
        if (sugBox) { sugBox.style.display = 'none'; sugBox.innerHTML = ''; }
        state.sugIndex = -1;
    }

    function renderSuggestions(list) {
        if (!sugBox || !list.length) { hideSuggestions(); return; }
        sugBox.innerHTML = list.map((s, i) =>
            `<div class="visits-suggestion-item" data-idx="${i}" data-name="${esc(s.name)}">
                <i class="fas fa-user sug-icon"></i>
                <span class="sug-name">${esc(s.name)}</span>
                <span class="sug-phone">${esc(s.phone)}</span>
            </div>`
        ).join('');
        sugBox.style.display = 'block';

        sugBox.querySelectorAll('.visits-suggestion-item').forEach(el => {
            el.addEventListener('mousedown', e => {
                e.preventDefault();
                searchInput.value = el.dataset.name;
                syncFilterLock();
                hideSuggestions();
                clearTimeout(state.searchTimer);
                fetchVisits(1, false);
            });
        });
    }

    function fetchSuggestions(q) {
        if (q.length < 2) { hideSuggestions(); return; }
        fetch(`${APP_URL}/ajax/ts_visits.php?mode=suggestions&q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } })
            .then(r => r.json())
            .then(data => { if (data.success) renderSuggestions(data.suggestions || []); })
            .catch(() => hideSuggestions());
    }

    // Keyboard navigation in suggestions
    function moveSug(dir) {
        const items = sugBox ? sugBox.querySelectorAll('.visits-suggestion-item') : [];
        if (!items.length) return;
        items[state.sugIndex]?.classList.remove('sug-active');
        state.sugIndex = Math.max(0, Math.min(items.length - 1, state.sugIndex + dir));
        items[state.sugIndex].classList.add('sug-active');
    }

    // ── Filter lock: while searching, the other filters are inactive ──
    const searchHint = document.getElementById('visits-search-hint');
    function syncFilterLock() {
        const locked = searchInput.value.trim() !== '';
        form.querySelectorAll('select, input[type="date"]').forEach(el => {
            el.disabled = locked;
            const fg = el.closest('.filter-group');
            if (fg) fg.classList.toggle('filter-locked', locked);
        });
        if (searchHint) searchHint.style.display = locked ? 'block' : 'none';
    }

    // ── Event listeners ───────────────────────────────────────
    searchInput.addEventListener('input', function () {
        const q = searchInput.value.trim();
        syncFilterLock();

        // suggestions
        clearTimeout(state.sugTimer);
        state.sugTimer = setTimeout(() => fetchSuggestions(q), 150);

        // search
        clearTimeout(state.searchTimer);
        state.searchTimer = setTimeout(() => { hideSuggestions(); fetchVisits(1, false); }, 380);
    });

    searchInput.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown')  { e.preventDefault(); moveSug(1); }
        if (e.key === 'ArrowUp')    { e.preventDefault(); moveSug(-1); }
        if (e.key === 'Enter') {
            const active = sugBox && sugBox.querySelector('.sug-active');
            if (active) { e.preventDefault(); searchInput.value = active.dataset.name; syncFilterLock(); hideSuggestions(); clearTimeout(state.searchTimer); fetchVisits(1, false); }
        }
        if (e.key === 'Escape') hideSuggestions();
    });

    searchInput.addEventListener('blur', () => setTimeout(hideSuggestions, 150));

    // Filters — instant
    form.querySelectorAll('select, input[type="date"]').forEach(el => {
        el.addEventListener('change', () => { clearTimeout(state.searchTimer); fetchVisits(1, false); });
    });

    // Block full form submit
    form.addEventListener('submit', e => { e.preventDefault(); clearTimeout(state.searchTimer); fetchVisits(1, false); });

    // Load more button
    if (loadMoreBtn) {
        loadMoreBtn.addEventListener('click', () => fetchVisits(state.page + 1, true));
    }

    // Infinite scroll via IntersectionObserver
    if (sentinel && 'IntersectionObserver' in window) {
        const io = new IntersectionObserver(entries => {
            if (entries[0].isIntersecting && !state.loading && state.page < state.totalPages) {
                fetchVisits(state.page + 1, true);
            }
        }, { rootMargin: '120px' });
        io.observe(sentinel);
    }

    // Click outside closes suggestions
    document.addEventListener('click', e => {
        if (!searchInput.contains(e.target) && !(sugBox && sugBox.contains(e.target))) hideSuggestions();
    });

    // Init load-more visibility + filter lock on first paint
    syncLoadMore();
    syncFilterLock();
})();
</script>

<?php if ($permission->can('page.new_visit')) require __DIR__ . '/includes/new_visit_dialog.php'; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
