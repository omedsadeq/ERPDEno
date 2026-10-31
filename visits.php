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
// No title row: the page's two buttons sit at the end of the summary strip.
$hidePageHeader = true;
require_once __DIR__ . '/classes/Permission.php';
$vvHeadButtons = '<a href="' . APP_URL . '/visits-report.php" class="vv-head-btn is-report" title="ڕاپۆرتی سەردانەکان"><i class="fas fa-chart-line"></i><span>ڕاپۆرت</span></a>'
    . (Permission::getInstance()->can('page.new_visit')
        ? '<a href="' . APP_URL . '/new_visit.php" data-new-visit-dialog class="vv-head-btn is-new"><i class="fas fa-plus"></i><span>سەردانی نوێ</span></a>'
        : '');

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/case-button.php';

// «پلانی چارەسەر» on a checkup row (ajax/treatment_plan.php quick_plan): a doctor's
// own plan; the admin and reception name the doctor, the visit's offered first.
$vvCanPlan = Permission::getInstance()->can('treatment_plan.write');
$vvPlanPickDoctor = !($isDoctor && !$isAdmin);
$vvPlanDoctors = [];
if ($vvCanPlan && $vvPlanPickDoctor) {
    foreach ($auth->getDoctors() as $d) {
        if (!empty($d['is_active'])) {
            $vvPlanDoctors[] = ['id' => (int)$d['id'], 'name' => (string)$d['full_name']];
        }
    }
}

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

// The rows themselves come from ajax/ts_visits.php (renderTable() below), for
// the first page too; the count above only seeds the pager.

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

?>

<style>
/* Modals, search suggestions, load-more / pagination (kept from the old sheet) */
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
@media (max-width: 767px) {
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
    .visits-suggestions-box {
        right: 0;
        left: 0;
        min-width: 0;
        max-height: 280px;
        overflow-y: auto;
    }
}
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

/* ══ سەردانەکان — layout (vv-*) ═══════════════════════════════════════
   One toolbar, one summary strip, one classic table. Tight on purpose:
   a desk screen shows a full page of visits; a phone shows each as a card. */
.vv { --vv-line: #e6eaef; --vv-head: #f4f6f9; --vv-ink: #1e293b; --vv-mute: #64748b; --vv-accent: var(--win-accent, #0078d4);
      display: flex; flex-direction: column; gap: 10px; color: var(--vv-ink); }
.vv-muted { color: #94a3b8; font-weight: 400; }

/* Title-row buttons (in the app's page header) */
.vv-head-btn { display: inline-flex; align-items: center; gap: 7px; height: 34px; padding: 0 14px; border-radius: 8px;
               font-size: 13px; font-weight: 700; text-decoration: none; color: #fff; white-space: nowrap; }
.vv-head-btn.is-new { background: #16a34a; }
.vv-head-btn.is-new:hover { background: #15803d; color: #fff; }
.vv-head-btn.is-report { background: #fff; color: #5b21b6; border: 1px solid #ddd6fe; }
.vv-head-btn.is-report:hover { background: #f5f3ff; color: #4c1d95; }

/* Toolbar */
.vv-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; padding: 10px 12px; background: #fff;
              border: 1px solid var(--vv-line); border-radius: 10px; }
.vv-search { position: relative; flex: 1 1 190px; min-width: 170px; }
.vv-search > i { position: absolute; top: 50%; inset-inline-start: 11px; transform: translateY(-50%); color: #94a3b8; font-size: 13px; pointer-events: none; }
.vv-search input { width: 100%; height: 36px; padding: 0 34px 0 10px; padding-inline-start: 34px; padding-inline-end: 10px;
                   border: 1px solid #d5dbe3; border-radius: 8px; font: inherit; font-size: 13.5px; background: #fbfcfd; }
.vv-search input:focus { outline: none; border-color: var(--vv-accent); background: #fff; box-shadow: 0 0 0 3px rgba(0, 120, 212, .12); }
.vv-dates { display: flex; gap: 6px; }
.vv-date { display: flex; align-items: center; height: 36px; border: 1px solid #d5dbe3; border-radius: 8px; overflow: hidden; background: #fbfcfd; margin: 0; }
.vv-date span { padding: 0 9px; font-size: 12px; font-weight: 700; color: var(--vv-mute); background: var(--vv-head); align-self: stretch; display: flex; align-items: center; border-inline-end: 1px solid #e2e8f0; }
.vv-date input { border: 0; height: 100%; padding: 0 8px; font: inherit; font-size: 13px; background: transparent; width: 122px; }
.vv-date input:focus { outline: none; }
.vv-ranges { display: flex; border: 1px solid #d5dbe3; border-radius: 8px; overflow: hidden; height: 36px; }
.vv-range { display: flex; align-items: center; padding: 0 11px; font-size: 12.5px; font-weight: 600; color: #475569; text-decoration: none; white-space: nowrap; background: #fff; }
.vv-range + .vv-range { border-inline-start: 1px solid #e2e8f0; }
.vv-range:hover { background: #f1f5f9; color: var(--vv-ink); }
.vv-range.is-active { background: var(--vv-accent); color: #fff; }
.vv-btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; height: 36px; padding: 0 12px; border: 1px solid #d5dbe3;
          border-radius: 8px; background: #fff; color: #334155; font: inherit; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; white-space: nowrap; }
.vv-btn:hover { background: #f1f5f9; color: var(--vv-ink); }
.vv-btn[aria-expanded="true"] { background: #eef6fc; border-color: #9cc7ec; color: #005a9e; }
.vv-btn.vv-icon { width: 36px; padding: 0; }
.vv-badge { min-width: 18px; height: 18px; padding: 0 5px; border-radius: 9px; background: var(--vv-accent); color: #fff; font-size: 11px; line-height: 18px; text-align: center; }
.vv-badge:empty { display: none; }
.vv-filters { flex-basis: 100%; display: flex; flex-wrap: wrap; gap: 8px; padding-top: 8px; border-top: 1px dashed var(--vv-line); }
.vv-filters[hidden] { display: none; }
.vv-field { display: flex; align-items: center; height: 34px; border: 1px solid #d5dbe3; border-radius: 8px; overflow: hidden; background: #fff; margin: 0; }
.vv-field span { padding: 0 9px; font-size: 12px; font-weight: 700; color: var(--vv-mute); background: var(--vv-head); align-self: stretch; display: flex; align-items: center; border-inline-end: 1px solid #e2e8f0; }
.vv-field select { border: 0; height: 100%; padding: 0 8px; font: inherit; font-size: 13px; background: transparent; min-width: 120px; }
.vv-field select:focus { outline: none; }
.vv-lockable.is-locked { opacity: .45; pointer-events: none; }
.vv-toolbar .visits-search-hint { flex-basis: 100%; margin: 0; }

/* Summary strip */
.vv-summary { display: flex; align-items: center; flex-wrap: wrap; gap: 4px 6px; padding: 6px 10px; background: #fff; border: 1px solid var(--vv-line); border-radius: 10px; transition: opacity .15s; }
.vv-stat { display: inline-flex; align-items: baseline; gap: 5px; padding: 3px 9px; border-radius: 7px; font-size: 12.5px; color: var(--vv-mute); white-space: nowrap; }
.vv-stat b { font-size: 15px; font-weight: 800; color: var(--vv-ink); font-variant-numeric: tabular-nums; }
.vv-stat.is-total { background: #eef6fc; } .vv-stat.is-total b { color: #005a9e; }
.vv-stat.is-checkup b { color: #0369a1; } .vv-stat.is-treatment b { color: #7c3aed; }
.vv-stat.is-new b { color: #15803d; } .vv-stat.is-old b { color: #b45309; } .vv-stat.is-treated b { color: #0f766e; }
.vv-sep { width: 1px; height: 18px; background: var(--vv-line); }
.vv-summary-actions, .vv-tool-actions { margin-inline-start: auto; display: flex; align-items: center; gap: 6px; }
.vv-tool-actions { display: none; }
.vv-print, .vv-summary-actions .vv-head-btn { height: 32px; }
.vv-tool-actions .vv-head-btn { height: 36px; }
.vv-tool-actions .vv-head-btn.is-report { width: 36px; }
.vv-print { width: 32px; }

/* Doctors' report (folded) */
.vv-report { background: #fff; border: 1px solid var(--vv-line); border-radius: 10px; overflow: hidden; }
.vv-report > summary { cursor: pointer; padding: 9px 12px; font-size: 13px; font-weight: 700; list-style: none; display: flex; align-items: center; gap: 8px; }
.vv-report > summary::-webkit-details-marker { display: none; }
.vv-report > summary::after { content: ''; width: 7px; height: 7px; border: solid #94a3b8; border-width: 0 2px 2px 0; transform: rotate(45deg); margin-inline-start: auto; transition: transform .2s; }
.vv-report[open] > summary::after { transform: rotate(-135deg); }
.vv-report[open] > summary { border-bottom: 1px solid var(--vv-line); }
.vv-tag { display: inline-flex; gap: 4px; margin: 1px 2px; padding: 1px 7px; border-radius: 6px; background: #f1f5f9; font-size: 11.5px; white-space: nowrap; }

/* The table — classic: ruled, striped, sticky head, one or two lines a row */
.vv-card { background: #fff; border: 1px solid var(--vv-line); border-radius: 10px; }
.vv-results { transition: opacity .15s; }
.vv-scroll { overflow-x: auto; }
.vv-table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 13px; }
.vv-table th { position: sticky; top: 0; z-index: 2; background: var(--vv-head); color: #334155; font-size: 12px; font-weight: 800; text-align: start;
               padding: 8px 6px; border-bottom: 1px solid #d9e0e8; white-space: nowrap; }
.vv-table th:first-child { border-start-start-radius: 10px; }
.vv-table th:last-child { border-start-end-radius: 10px; }
.vv-table td { padding: 6px; border-bottom: 1px solid #eef1f4; vertical-align: middle; line-height: 1.45; }
.vv-table tbody tr:nth-child(even) td { background: #fafbfc; }
.vv-table tbody tr:hover td { background: #f2f8fe; }
.vv-table tbody tr:last-child td { border-bottom: 0; }
.vv-table .c-no { width: 1%; color: #94a3b8; font-size: 12px; text-align: center; font-variant-numeric: tabular-nums;
                  border-inline-start: 3px solid transparent; }
.vv-table tr.is-checkup .c-no { border-inline-start-color: #38bdf8; }
.vv-table tr.is-treatment .c-no { border-inline-start-color: #a78bfa; }
.vv-num { text-align: center; font-variant-numeric: tabular-nums; }
.vv-sub { display: block; font-size: 11.5px; color: var(--vv-mute); white-space: nowrap; }

.vv-pt { display: flex; align-items: center; gap: 8px; min-width: 150px; }
.vv-name { font-weight: 700; color: var(--vv-ink); white-space: nowrap; }
.vv-link { flex-shrink: 0; width: 18px; height: 18px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 9.5px; color: #fff; }
.vv-link.is-linked { background: #16a34a; }
.vv-link.is-match { background: #f59e0b; }
.vv-link.is-none { background: #cbd5e1; }

.vv-chip { display: inline-block; padding: 1px 8px; border-radius: 6px; font-size: 11.5px; font-weight: 700; white-space: nowrap; margin: 1px 0; }
.vv-chip.is-checkup { background: #e0f2fe; color: #0369a1; }
.vv-chip.is-treatment { background: #ede9fe; color: #6d28d9; }
.vv-chip.is-new { background: #dcfce7; color: #15803d; }
.vv-chip.is-old { background: #fef3c7; color: #b45309; }
.vv-chip.is-treated { background: #ccfbf1; color: #0f766e; }
.vv-doc { font-weight: 600; }
.vv-table .c-doc { min-width: 110px; max-width: 160px; }
.vv-table .c-work { max-width: 190px; }
.vv-table .c-doc .vv-sub, .vv-table .c-work .vv-sub { white-space: normal; }
.vv-work { max-width: 190px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: block; }
.vv-price { font-variant-numeric: tabular-nums; white-space: nowrap; font-weight: 600; }
.vv-apt { display: inline-flex; flex-direction: column; padding: 2px 6px; border-radius: 7px; font-size: 11px; font-weight: 700; white-space: nowrap;
          color: var(--c, #16a34a); background: color-mix(in srgb, var(--c, #16a34a) 10%, #fff); border: 1px solid color-mix(in srgb, var(--c, #16a34a) 35%, #fff); }
.vv-apt small { font-weight: 500; opacity: .85; }

.vv-actions { display: flex; align-items: center; gap: 4px; justify-content: flex-end; }
.vv-act { width: 30px; height: 30px; border: 1px solid #e2e8f0; border-radius: 7px; background: #fff; color: #475569; display: inline-flex;
          align-items: center; justify-content: center; cursor: pointer; font-size: 13px; text-decoration: none; }
.vv-act:hover { background: #f1f5f9; color: var(--vv-ink); }
.vv-act.is-waiting:hover { background: #fff7ed; color: #c2410c; border-color: #fed7aa; }
.vv-act.is-link { color: #b45309; border-color: #fde68a; background: #fffbeb; }
.vv-act.is-more.is-open { background: #e2e8f0; }
.vv-actions .case-go-btn { height: 30px; }
.vv-actions .plan-go-btn { height: 30px; font-size: 12px; }

.vv-menu { position: fixed; z-index: 3000; min-width: 210px; padding: 6px; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px;
           box-shadow: 0 12px 32px rgba(15, 23, 42, .16); direction: rtl; }
.vv-menu[hidden] { display: none; }
.vv-menu button, .vv-menu a { display: flex; align-items: center; gap: 10px; width: 100%; min-height: 36px; padding: 7px 10px; border: 0; border-radius: 7px;
           background: none; font: inherit; font-size: 13px; color: var(--vv-ink); text-align: start; cursor: pointer; text-decoration: none; }
.vv-menu button:hover, .vv-menu a:hover { background: #f1f5f9; }
.vv-menu i { width: 18px; text-align: center; color: #64748b; }
.vv-menu .is-danger, .vv-menu .is-danger i { color: #dc2626; }
.vv-menu hr { border: 0; border-top: 1px solid #eef1f4; margin: 4px 2px; }

.vv-loading, .vv-empty { padding: 34px 12px; text-align: center; color: #94a3b8; font-size: 13.5px; }
.vv-empty i { display: block; font-size: 28px; margin-bottom: 8px; color: #cbd5e1; }
.vv-empty b { display: block; color: #475569; margin-bottom: 3px; }

/* The breakpoints follow the page's own width (container queries), not the
   screen's: an open or closed sidebar changes the room the table really has. */
.vv { container: vv / inline-size; }
.vv-pt-meta, .vv-when-line { display: flex; align-items: center; flex-wrap: wrap; gap: 2px 6px; }
.vv-table .vv-chip { font-size: 10.5px; padding: 0 6px; margin: 0; }
/* A tight table breaks the time after «→» instead of pushing the table wider */
.vv-table .vv-when-time { white-space: normal; }
.vv-when-time > span { white-space: nowrap; }

/* Narrower desks: no «#», «کەیس» and «پلان» are icon-only */
@container vv (max-width: 1000px) {
    .vv-scroll-main { overflow-x: auto; }
    .vv-table th { position: static; }
    .vv-results .vv-table .c-no { display: none; }
    .vv-results .vv-table tr.is-checkup .c-patient { box-shadow: inset -3px 0 0 #38bdf8; }
    .vv-results .vv-table tr.is-treatment .c-patient { box-shadow: inset -3px 0 0 #a78bfa; }
    .vv-actions .case-go-btn span, .vv-actions .plan-go-btn span { display: none; }
    .vv-actions .plan-go-btn { width: 30px; padding: 0; }
    .vv-toolbar { gap: 6px; }
    .vv-search { flex-basis: 140px; min-width: 140px; }
    .vv-range { padding: 0 9px; }
    .vv-head-btn.is-report { width: 34px; padding: 0; justify-content: center; }
    .vv-head-btn.is-report span { display: none; }
}

/* Tablets and phones: each visit is a card, as many side by side as fit */
@container vv (max-width: 820px) {
    .vv-results .vv-table thead { display: none; }
    .vv-results .vv-table { display: block; }
    .vv-results .vv-table tbody { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 8px; padding: 8px; }
    .vv-results .vv-table tr { position: relative; display: grid; grid-template-columns: 1fr 1fr; gap: 8px 12px; padding: 10px 12px;
                               align-content: start; border: 1px solid var(--vv-line); border-inline-start: 3px solid #cbd5e1; border-radius: 9px; background: #fff; }
    .vv-results .vv-table tr.is-checkup { border-inline-start-color: #38bdf8; }
    .vv-results .vv-table tr.is-treatment { border-inline-start-color: #a78bfa; }
    .vv-results .vv-table td { display: block; padding: 0; border: 0; background: none !important; box-shadow: none !important; min-width: 0; max-width: none; }
    .vv-results .vv-table td.c-patient { grid-column: 1 / -1; padding-inline-end: 118px; min-height: 34px; }
    .vv-results .vv-table td.c-act { position: absolute; top: 9px; inset-inline-end: 10px; }
    .vv-results .vv-table td[data-label]::before { content: attr(data-label); display: block; color: #94a3b8; font-size: 10.5px; font-weight: 700; margin-bottom: 1px; }
    .vv-results .vv-table td.is-empty { display: none; }
    .vv-results .vv-table td.c-work { grid-column: 1 / -1; }
    .vv-results .vv-table .vv-sub { white-space: normal; }
    .vv-name { white-space: normal; }
    .vv-work { max-width: none; white-space: normal; }
    .vv-pt { min-width: 0; }
    .vv-card { background: #f6f8fa; }
    .vv-tool-actions { display: flex; }
    .vv-summary-actions .vv-head-btn { display: none; }
}

/* Phones: the toolbar stacks, the numbers wrap */
@container vv (max-width: 640px) {
    .vv-toolbar { padding: 8px; }
    .vv-search { flex-basis: 100%; }
    .vv-dates { flex: 1 1 100%; }
    .vv-date { flex: 1; }
    .vv-date input { width: 100%; min-width: 0; }
    .vv-ranges { flex: 1 1 auto; }
    .vv-range { flex: 1; justify-content: center; padding: 0 6px; }
    .vv-filters .vv-field { flex: 1 1 calc(50% - 4px); }
    .vv-field select { min-width: 0; width: 100%; }
    .vv-sep, .vv-print { display: none; }
    .vv-tool-actions { order: -1; flex-basis: 100%; margin: 0; }
    .vv-tool-actions .vv-head-btn { flex: 1; width: auto; padding: 0 12px; justify-content: center; height: 36px; }
    .vv-head-btn.is-report span { display: inline; }
    .vv-stat { padding: 2px 6px; }
    .vv-results .vv-table tbody { padding: 6px; gap: 6px; }
}

@media print {
    .vv-toolbar, .vv-actions, .vv-print, .c-act, #visits-load-section, .pagination-section, .vv-head-btn { display: none !important; }
    .vv-card, .vv-summary { border: 0; }
    .vv-table th { position: static; }
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

<div class="vv">
    <!-- Toolbar: search · dates · quick ranges · the rest of the filters behind «فلتەر» -->
    <form method="GET" action="" id="visits-filter-form" class="vv-toolbar" autocomplete="off">
        <div class="vv-search">
            <i class="fas fa-search"></i>
            <input type="search" id="visits-search-input" name="search" value="<?= htmlspecialchars($search) ?>"
                   placeholder="گەڕان بە ناو یان ژمارەی مۆبایل..." autocomplete="off" aria-label="گەڕان">
            <div id="visits-suggestions" class="visits-suggestions-box" style="display:none;"></div>
        </div>

        <div class="vv-dates vv-lockable">
            <label class="vv-date"><span>لە</span><input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>"></label>
            <label class="vv-date"><span>بۆ</span><input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>"></label>
        </div>

        <!-- Quick ranges — plain links so they survive a reload/bookmark -->
        <div class="vv-ranges vv-lockable" role="group" aria-label="ماوەی خێرا">
            <?php
            $ranges = ['today' => 'ئەمڕۆ', 'yesterday' => 'دوێنێ', 'week' => 'ئەم هەفتەیە', 'month' => 'ئەم مانگە'];
            foreach ($ranges as $key => $label):
            ?>
            <a href="<?= APP_URL ?>/visits.php?range=<?= $key ?>" class="vv-range <?= $range_filter === $key ? 'is-active' : '' ?>"><?= $label ?></a>
            <?php endforeach; ?>
        </div>

        <?php
        $vvActiveFilters = (!$isDoctor && $doctor_filter !== '' && $doctor_filter !== null ? 1 : 0)
            + ($doctor_field_filter !== '' ? 1 : 0) + ($status_filter !== '' ? 1 : 0) + ($visit_type_filter !== '' ? 1 : 0);
        ?>
        <button type="button" class="vv-btn" id="vvFiltersBtn" aria-expanded="<?= $vvActiveFilters ? 'true' : 'false' ?>" aria-controls="vvFilters">
            <i class="fas fa-sliders-h"></i> فلتەر
            <span class="vv-badge" id="vvFilterCount"><?= $vvActiveFilters ?: '' ?></span>
        </button>
        <a href="<?= APP_URL ?>/visits.php" class="vv-btn vv-icon" title="پاککردنەوەی فلتەرەکان"><i class="fas fa-undo"></i></a>
        <div class="vv-tool-actions"><?= $vvHeadButtons ?></div>

        <div class="vv-filters vv-lockable" id="vvFilters" <?= $vvActiveFilters ? '' : 'hidden' ?>>
            <?php if (!$isDoctor): ?>
            <label class="vv-field"><span>دکتۆر</span>
                <select name="doctor">
                    <option value="">هەموو</option>
                    <?php foreach ($doctors as $doctor): ?>
                    <option value="<?= $doctor['id'] ?>" <?= $doctor_filter == $doctor['id'] ? 'selected' : '' ?>><?= htmlspecialchars($doctor['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php endif; ?>
            <label class="vv-field"><span>بەپێی</span>
                <select name="doctor_field">
                    <option value="">هەموو ڕۆڵەکان</option>
                    <option value="assigned" <?= $doctor_field_filter === 'assigned' ? 'selected' : '' ?>>د. بینراو</option>
                    <?php if ($hasVisitDoctorCols): ?>
                    <option value="checkup" <?= $doctor_field_filter === 'checkup' ? 'selected' : '' ?>>د. فەحس</option>
                    <option value="treatment" <?= $doctor_field_filter === 'treatment' ? 'selected' : '' ?>>د. ئیش</option>
                    <?php endif; ?>
                </select>
            </label>
            <label class="vv-field"><span>دۆخ</span>
                <select name="status">
                    <option value="">هەموو</option>
                    <option value="new" <?= $status_filter === 'new' ? 'selected' : '' ?>>نوێ</option>
                    <option value="old" <?= $status_filter === 'old' ? 'selected' : '' ?>>کۆن</option>
                </select>
            </label>
            <label class="vv-field"><span>جۆر</span>
                <select name="visit_type">
                    <option value="">هەموو</option>
                    <option value="checkup" <?= $visit_type_filter === 'checkup' ? 'selected' : '' ?>>پشکنین</option>
                    <option value="treatment" <?= $visit_type_filter === 'treatment' ? 'selected' : '' ?>>ئیش</option>
                </select>
            </label>
        </div>
        <small id="visits-search-hint" class="visits-search-hint" style="display:none;"><i class="fas fa-globe"></i> گەڕان لە هەموو سەردانەکاندایە — بەروار و فلتەرەکان ناچالاکن</small>
    </form>

    <!-- Summary of whatever is currently filtered (numbers swapped in by the live search) -->
    <div class="vv-summary summary-strip">
        <?php if (!empty($summary_stats)): ?>
        <span class="vv-stat is-total"><b data-summary="total_visits"><?= (int)$summary_stats['total_visits'] ?></b> سەردان</span>
        <span class="vv-stat"><b data-summary="unique_patients"><?= (int)$summary_stats['unique_patients'] ?></b> نەخۆش</span>
        <i class="vv-sep"></i>
        <span class="vv-stat is-checkup"><b data-summary="checkup_visits"><?= (int)$summary_stats['checkup_visits'] ?></b> پشکنین</span>
        <span class="vv-stat is-treatment"><b data-summary="treatment_visits"><?= (int)$summary_stats['treatment_visits'] ?></b> ئیش</span>
        <i class="vv-sep"></i>
        <span class="vv-stat is-new"><b data-summary="new_patients"><?= (int)$summary_stats['new_patients'] ?></b> نوێ</span>
        <span class="vv-stat is-old"><b data-summary="old_patients"><?= (int)$summary_stats['old_patients'] ?></b> کۆن</span>
        <span class="vv-stat is-treated"><b data-summary="treated_visits"><?= (int)$summary_stats['treated_visits'] ?></b> چارەسەرکراو</span>
        <?php endif; ?>
        <div class="vv-summary-actions">
            <button type="button" class="vv-btn vv-icon vv-print" onclick="window.print()" title="چاپکردن"><i class="fas fa-print"></i></button>
            <?= $vvHeadButtons ?>
        </div>
    </div>

    <!-- Doctor's report — folded, it is a summary of the day, not the list -->
    <?php if (!empty($doctors_report)): ?>
    <details class="vv-report">
        <summary><i class="fas fa-user-md"></i> ڕاپۆرتی دکتۆرەکان <span class="vv-muted">— <?= date('Y-m-d') ?> · <?= count($doctors_report) ?> دکتۆر</span></summary>
        <div class="vv-scroll">
            <table class="vv-table vv-report-table no-responsive">
                <thead>
                    <tr><th>دکتۆر</th><th>کۆ</th><th>داواکراو</th><th>بینراو</th><th>نوێ</th><th>کۆن</th><th>ئیشەکان</th><th>سەرچاوەکان</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($doctors_report as $doctor_stat): $doctor_id = $doctor_stat['doctor_id']; ?>
                    <tr>
                        <td><b><?= htmlspecialchars($doctor_stat['doctor_name']) ?></b></td>
                        <td class="vv-num"><b><?= (int)$doctor_stat['total_patients'] ?></b></td>
                        <td class="vv-num"><?= (int)$doctor_stat['requested_patients'] ?></td>
                        <td class="vv-num"><?= (int)$doctor_stat['assigned_patients'] ?></td>
                        <td class="vv-num"><?= (int)$doctor_stat['new_patients'] ?></td>
                        <td class="vv-num"><?= (int)$doctor_stat['old_patients'] ?></td>
                        <td><?php foreach ($procedure_breakdown[$doctor_id] ?? [] as $proc): ?><span class="vv-tag"><?= htmlspecialchars($proc['procedure_treatment']) ?> <b><?= (int)$proc['count'] ?></b></span><?php endforeach; ?><?= empty($procedure_breakdown[$doctor_id]) ? '<span class="vv-muted">—</span>' : '' ?></td>
                        <td><?php foreach ($source_breakdown[$doctor_id] ?? [] as $source): ?><span class="vv-tag"><?= htmlspecialchars($source['referral_source']) ?> <b><?= (int)$source['count'] ?></b></span><?php endforeach; ?><?= empty($source_breakdown[$doctor_id]) ? '<span class="vv-muted">—</span>' : '' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </details>
    <?php endif; ?>

    <!-- The list: drawn by renderTable() below from ajax/ts_visits.php, the one
         renderer for the first page, the filters and the search alike. -->
    <div class="vv-card">
        <div id="visits-results" class="vv-results">
            <div class="vv-loading"><i class="fas fa-spinner fa-spin"></i> بار دەکرێت…</div>
        </div>

        <!-- Pagination (outside #visits-results so renders don't destroy it) -->
        <div id="visits-pagination" class="pagination-section" style="display:none;"></div>

        <!-- Load more / Infinite scroll sentinel -->
        <div id="visits-load-section">
            <div id="visits-scroll-sentinel" style="height:1px;"></div>
            <div id="visits-spinner" style="display:none;">
                <div class="spinner-ring"></div>
                <span>بارکردن...</span>
            </div>
            <button id="btn-load-more" style="display:none;">
                <i class="fas fa-chevron-down"></i> زیاتر بارکە
            </button>
            <p id="visits-end-msg" style="display:none;">
                <i class="fas fa-check-circle"></i> هەمووی نیشاندرا
            </p>
        </div>
    </div>
</div>

<!-- The row's ⋯ menu: one, filled for the row it is opened on -->
<div class="vv-menu" id="vvMenu" role="menu" hidden></div>

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
    const CAN_PLAN        = <?= json_encode($vvCanPlan) ?>;
    const PLAN_PICK_DOCTOR = <?= json_encode($vvPlanPickDoctor) ?>;
    const PLAN_DOCTORS    = <?= json_encode($vvPlanDoctors, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;

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

    // ── Rows ─────────────────────────────────────────────────
    // The one renderer: the first page, the filters, the search and «زیاتر»
    // all draw rows here from ajax/ts_visits.php. Buttons carry only the
    // visit id; the click handler below looks the visit up in `rowsById`, so
    // a name with an apostrophe can never break an onclick again.
    const rowsById = new Map();
    const APT_COLOURS = { confirmed: '#0078d4', waiting: '#f57c00', in_progress: '#2196f3', rescheduled: '#ff9800' };
    const money = n => Number(n || 0).toLocaleString('en-US', { maximumFractionDigits: 2 });
    const dash = '<span class="vv-muted">—</span>';

    function doctorCell(v) {
        const main = v.assigned_doctor_name || v.checkup_doctor_name || v.treatment_doctor_name || v.requested_doctor_name || '';
        const extra = [];
        if (v.requested_doctor_name && v.requested_doctor_name !== main) extra.push('داواکراو: ' + esc(v.requested_doctor_name));
        if (v.checkup_doctor_name && v.checkup_doctor_name !== main) extra.push('فەحس: ' + esc(v.checkup_doctor_name));
        if (v.treatment_doctor_name && v.treatment_doctor_name !== main) extra.push('ئیش: ' + esc(v.treatment_doctor_name));
        if (!main) return '';
        return `<span class="vv-doc">${esc(main)}</span>${extra.length ? `<span class="vv-sub">${extra.join(' · ')}</span>` : ''}`;
    }

    function renderRow(v, rowNum) {
        rowsById.set(Number(v.id), v);
        const isTreatment = v.visit_type === 'treatment';
        const link = v.is_linked
            ? ['is-linked', 'fa-check', 'تۆمارکراوە لە نەخۆشەکان']
            : (v.has_phone_match ? ['is-match', 'fa-exclamation', 'نەخۆشێکی هاوشێوە هەیە — پەیوەستی بکە'] : ['is-none', 'fa-minus', 'لە نەخۆشەکاندا نییە']);
        let time = v.arrival_time_fmt && v.arrival_time_fmt !== '-' ? v.arrival_time_fmt : '';
        const checkin = v.checkin_time_fmt && v.checkin_time_fmt !== '-' ? v.checkin_time_fmt : '';
        // «10:02 → 10:17 PM»: the AM/PM once when both halves share it
        if (time && checkin && time.slice(-2) === checkin.slice(-2)) time = time.slice(0, -3);
        // The visit cell: its date and kind (and ✓ when treated), the times below
        const when = `<span class="vv-when-line"><span class="vv-when-date">${esc(v.visit_date_fmt)}</span>`
            + `<span class="vv-chip ${isTreatment ? 'is-treatment' : 'is-checkup'}">${isTreatment ? 'ئیش' : 'پشکنین'}</span>`
            + (v.treated === 'yes' ? '<span class="vv-chip is-treated" title="چارەسەرکرا"><i class="fas fa-check"></i></span>' : '') + '</span>'
            + (time ? `<span class="vv-sub vv-when-time" dir="ltr" title="هاتن → چوونە ژوورەوە"><span>${esc(time)}</span>${checkin ? ' → <span>' + esc(checkin) + '</span>' : ''}</span>` : '');
        // new or returning belongs to the patient: beside the phone
        const statusChip = `<span class="vv-chip ${v.patient_status === 'new' ? 'is-new' : 'is-old'}">${v.patient_status === 'new' ? 'نوێ' : 'کۆن'}</span>`;
        const docs = doctorCell(v);
        const workHtml = v.procedure_treatment ? `<span class="vv-work" title="${esc(v.procedure_treatment)}">${esc(v.procedure_treatment)}</span>` : '';
        const srcHtml = v.referral_source ? `<span class="vv-sub">سەرچاوە: ${esc(v.referral_source)}</span>` : '';
        const price = isTreatment || !Number(v.price) ? '' : `<span class="vv-price">${money(v.price)}</span>`;
        let apt = '';
        if (v.upcoming) {
            const c = APT_COLOURS[v.upcoming.status] || '#16a34a';
            apt = `<span class="vv-apt" style="--c:${c}" title="${v.upcoming.doctor ? 'د. ' + esc(v.upcoming.doctor) : ''}"><span><i class="fas fa-calendar-check"></i> ${esc(v.upcoming.date)}</span><small dir="ltr">${esc(v.upcoming.time)}</small></span>`;
        }
        const firstAct = v.can_case
            ? `<button type="button" class="case-go-btn case-go-btn--sm" onclick="event.stopPropagation(); openPatientCase(${parseInt(v.linked_patient_id, 10)}, this, 0)" title="ڕاستەوخۆ بۆ چارتی ددانی کەیسەکە — ئەگەر نەبێت، دروست دەبێت"><i class="fas fa-tooth"></i><span>کەیس</span></button>`
            : (!v.is_linked ? `<button type="button" class="vv-act is-link" data-act="sync" title="${v.has_phone_match ? 'پەیوەستکردن بە نەخۆشێکی هەبوو' : 'زیادکردن بۆ نەخۆشەکان'}"><i class="fas fa-user-plus"></i></button>` : '');
        const planAct = CAN_PLAN && v.is_linked && v.visit_type === 'checkup'
            ? `<button type="button" class="plan-go-btn" data-act="plan" title="پلانی چارەسەر — دروستکردن و کردنەوەی"><i class="fas fa-clipboard-list"></i><span>پلان</span></button>`
            : '';
        const cell = (cls, label, html) => `<td class="${cls}${html ? '' : ' is-empty'}" data-label="${label}">${html || dash}</td>`;
        return `<tr class="${isTreatment ? 'is-treatment' : 'is-checkup'}" data-id="${v.id}">
            <td class="c-no">${rowNum}</td>
            <td class="c-patient"><div class="vv-pt"><span class="vv-link ${link[0]}" title="${link[2]}"><i class="fas ${link[1]}"></i></span>
                <div><span class="vv-name">${esc(v.patient_name)}</span><span class="vv-pt-meta"><span class="vv-sub" dir="ltr">${esc(v.mobile_number)}</span>${statusChip}</span></div></div></td>
            ${cell('c-when', 'سەردان', when)}
            ${cell('c-doc', 'دکتۆر', docs)}
            ${cell('c-work', 'ئیش', workHtml + srcHtml)}
            ${cell('c-price', 'نرخ', price)}
            ${cell('c-apt', 'مەوعید', apt)}
            <td class="c-act"><div class="vv-actions">${firstAct}${planAct}
                <button type="button" class="vv-act is-waiting" data-act="waiting" title="ناردن بۆ ژووری چاوەڕوانی"><i class="fas fa-user-clock"></i></button>
                <button type="button" class="vv-act is-more" data-act="more" title="زیاتر" aria-haspopup="menu"><i class="fas fa-ellipsis-vertical"></i></button>
            </div></td>
        </tr>`;
    }

    const TABLE_HEAD = `<thead><tr>
        <th class="c-no">#</th><th class="c-patient">نەخۆش</th><th class="c-when">سەردان</th>
        <th class="c-doc">دکتۆر</th><th class="c-work">ئیش / سەرچاوە</th><th class="c-price">نرخ</th><th class="c-apt">مەوعید</th><th class="c-act"></th>
    </tr></thead>`;

    // ── Full table replace (first paint, new filter or search) ─
    function renderTable(data) {
        if (countBadge) countBadge.textContent = data.total;
        applySummary(data.summary);
        rowsById.clear();
        state.page       = data.page || 1;
        state.totalPages = data.total_pages || 0;
        state.total      = data.total || 0;
        if (!data.visits || data.visits.length === 0) {
            resultsDiv.innerHTML = `<div class="vv-empty"><i class="fas fa-calendar-times"></i><b>هیچ سەردانێک نەدۆزرایەوە</b>بەروار یان فلتەرەکان بگۆڕە</div>`;
        } else {
            const offset = (data.page - 1) * data.per_page;
            const rows   = data.visits.map((v, i) => renderRow(v, offset + i + 1)).join('');
            resultsDiv.innerHTML = `<div class="vv-scroll-main"><table class="vv-table no-responsive">${TABLE_HEAD}<tbody id="visits-tbody">${rows}</tbody></table></div>`;
        }
        renderPagination(state.page, state.totalPages, state.total, data.per_page || 10);
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

    // ── Row buttons and the ⋯ menu ────────────────────────────
    const menu = document.getElementById('vvMenu');
    let menuFor = null;
    function closeMenu() {
        if (!menu || menu.hidden) return;
        menu.hidden = true;
        if (menuFor) menuFor.classList.remove('is-open');
        menuFor = null;
    }
    function openMenu(btn, v) {
        if (!menu) return;
        if (menuFor === btn) { closeMenu(); return; }
        closeMenu();
        const items = [];
        if (v.is_linked) {
            items.push(`<a href="${APP_URL}/patients/view.php?id=${parseInt(v.linked_patient_id, 10)}"><i class="fas fa-id-card"></i> پرۆفایلی نەخۆش</a>`);
        } else {
            items.push(`<button type="button" data-act="sync"><i class="fas fa-user-plus"></i> ${v.has_phone_match ? 'پەیوەستکردن بە نەخۆشێکی هەبوو' : 'زیادکردن بۆ نەخۆشەکان'}</button>`);
        }
        items.push(`<button type="button" data-act="appointment"><i class="fas fa-calendar-plus"></i> مەوعیدێکی نوێ</button>`);
        if (HAS_FULL_ACCESS) {
            items.push('<hr>');
            items.push(`<a href="${APP_URL}/edit_visit.php?id=${parseInt(v.id, 10)}"><i class="fas fa-pen"></i> دەستکاری</a>`);
            items.push(`<button type="button" data-act="delete" class="is-danger"><i class="fas fa-trash"></i> سڕینەوە</button>`);
        }
        menu.innerHTML = items.join('');
        menu.dataset.id = v.id;
        menu.hidden = false;
        const r = btn.getBoundingClientRect();
        const left = Math.max(8, Math.min(r.right - menu.offsetWidth, window.innerWidth - menu.offsetWidth - 8));
        const below = window.innerHeight - r.bottom;
        menu.style.left = left + 'px';
        menu.style.top = (below < menu.offsetHeight + 12 ? Math.max(8, r.top - menu.offsetHeight - 4) : r.bottom + 4) + 'px';
        btn.classList.add('is-open');
        menuFor = btn;
    }
    function runAction(act, v, btn) {
        if (act === 'more') { openMenu(btn, v); return; }
        closeMenu();
        if (act === 'sync') openSyncPatientModal(v.id, v.patient_name, v.mobile_number, !!v.has_phone_match);
        else if (act === 'waiting') openWaitingModal(v.patient_id, v.patient_name, v.visit_type || '', v.work_type_id ? parseInt(v.work_type_id, 10) : null, v.id);
        else if (act === 'appointment') openAppointmentForPatient(v.patient_id, v.patient_name, v.mobile_number);
        else if (act === 'delete') confirmDeleteVisit(v.id);
        else if (act === 'plan') newPlan(v, btn);
    }

    // «پلانی چارەسەر» in one click, as on the patient's plans tab: made (or
    // today's reopened) and opened on its own page. Only whose plan it is may be asked.
    function newPlan(v, btn) {
        const go = doctorId => {
            btn.disabled = true;
            const fd = new FormData();
            fd.append('action', 'quick_plan');
            fd.append('patient_id', parseInt(v.linked_patient_id, 10));
            if (doctorId) fd.append('doctor_id', doctorId);
            fetch(APP_URL + '/ajax/treatment_plan.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(r => r.json())
                .catch(() => ({ success: false, error: 'هەڵە لە پەیوەندی' }))
                .then(res => {
                    if (!res || !res.success) {
                        btn.disabled = false;
                        const msg = (res && (res.error || res.message)) || 'هەڵە';
                        if (typeof window.showToast === 'function') window.showToast('error', msg); else alert(msg);
                        return;
                    }
                    location.href = APP_URL + '/treatment-plans/view.php?id=' + parseInt(res.plan_id, 10);
                });
        };
        if (!PLAN_PICK_DOCTOR) { go(); return; }
        const docs = new Map(PLAN_DOCTORS.map(d => [String(d.id), d.name]));
        const lead = String(v.plan_doctor_id || '');
        Swal.fire({
            title: 'پلان بۆ کام دکتۆر؟', text: v.patient_name, input: 'select', inputOptions: docs, inputPlaceholder: 'دکتۆر هەڵبژێرە',
            inputValue: docs.has(lead) ? lead : '',
            showCancelButton: true, confirmButtonText: 'دروستکردن', cancelButtonText: 'نەخێر', confirmButtonColor: '#0d9488',
            inputValidator: val => val ? null : 'دکتۆر هەڵبژێرە'
        }).then(r => { if (r.isConfirmed && r.value) go(r.value); });
    }
    resultsDiv.addEventListener('click', function (e) {
        const btn = e.target.closest('[data-act]');
        if (!btn) return;
        const tr = btn.closest('tr[data-id]');
        const v = tr && rowsById.get(Number(tr.dataset.id));
        if (v) runAction(btn.dataset.act, v, btn);
    });
    if (menu) {
        menu.addEventListener('click', function (e) {
            const btn = e.target.closest('[data-act]');
            const v = rowsById.get(Number(menu.dataset.id));
            if (btn && v) runAction(btn.dataset.act, v, btn);
        });
        document.addEventListener('click', e => { if (!e.target.closest('#vvMenu, [data-act="more"]')) closeMenu(); });
        document.addEventListener('keydown', e => { if (e.key === 'Escape') closeMenu(); });
        window.addEventListener('scroll', closeMenu, true);
        window.addEventListener('resize', closeMenu);
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
        form.querySelectorAll('select, input[type="date"]').forEach(el => { el.disabled = locked; });
        form.querySelectorAll('.vv-lockable').forEach(el => el.classList.toggle('is-locked', locked));
        if (searchHint) searchHint.style.display = locked ? 'block' : 'none';
    }

    // «فلتەر» opens the doctor / role / status / type row; its badge counts
    // the ones in use, so a closed row never hides an active filter.
    const filtersBtn = document.getElementById('vvFiltersBtn');
    const filtersBox = document.getElementById('vvFilters');
    const filterCount = document.getElementById('vvFilterCount');
    function syncFilterCount() {
        if (!filtersBox || !filterCount) return;
        const n = Array.from(filtersBox.querySelectorAll('select')).filter(s => s.value !== '').length;
        filterCount.textContent = n ? n : '';
    }
    if (filtersBtn && filtersBox) {
        filtersBtn.addEventListener('click', function () {
            filtersBox.hidden = !filtersBox.hidden;
            filtersBtn.setAttribute('aria-expanded', filtersBox.hidden ? 'false' : 'true');
        });
        filtersBox.addEventListener('change', syncFilterCount);
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

    // First paint: the list comes from the same endpoint as every later
    // render, so the page and the live search can never show different rows.
    syncFilterLock();
    syncFilterCount();
    fetchVisits(state.page, false);
})();
</script>

<?php if ($permission->can('page.new_visit')) require __DIR__ . '/includes/new_visit_dialog.php'; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
