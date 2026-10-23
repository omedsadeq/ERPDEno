<?php
/**
 * Patients List Page
 * سیستەمی زیرەکی کلینیکی ددان
 *
 * Doctor Privacy System:
 * - Doctors see only their own patients by default
 * - Can search all clinic patients with restricted view
 * - Admin/Staff see all patients
 */

// Handle delete BEFORE any output — POST only, CSRF-checked, re-authenticated,
// soft delete + audit (so the redirect and 403 actually work).
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Patient.php';
require_once __DIR__ . '/../classes/AccessGuard.php';
require_once __DIR__ . '/../classes/XrayManager.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_patient') {
    $authGate = new Auth();
    $authGate->requireLogin();
    Csrf::require();
    $deleteId = intval($_POST['patient_id'] ?? 0);

    // Any role may delete, but a doctor only reaches patients they are already
    // allowed to see — no-op for admin/receptionist, 403 for an unrelated doctor.
    AccessGuard::requirePatientGeneral($deleteId);

    // Re-authenticate. A valid session is not enough: the password must be
    // re-typed, so an unattended logged-in machine cannot erase patients.
    $me   = $authGate->getCurrentUser();
    $hash = Database::getInstance()->fetch(
        "SELECT password FROM users WHERE id = :id AND is_active = 1",
        ['id' => (int)($me['id'] ?? 0)]
    );
    if (!$hash || !password_verify((string)($_POST['confirm_password'] ?? ''), $hash['password'])) {
        AuditLog::log('delete_patient_denied', 'patients', $deleteId, null, ['reason' => 'bad_password']);
        header('Location: ' . APP_URL . '/patients/index.php?delete_err=pw');
        exit;
    }

    if ($deleteId > 0) {
        (new Patient())->delete($deleteId); // soft delete (see Patient::delete)
    }
    header('Location: ' . APP_URL . '/patients/index.php?deleted=1');
    exit;
}

$pageTitle = 'نەخۆشەکان';
$breadcrumb = [['title' => 'نەخۆشەکان']];

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../classes/Patient.php';

$patientManager = new Patient();
$currentUser = $auth->getCurrentUser();
$isDoctor = $auth->isDoctor();
$isAdmin = $auth->isAdmin();

// Pagination
$page = max(1, intval($_GET['page'] ?? 1));
$search = trim($_GET['search'] ?? '');

// Doctor-specific filtering. Whitelisted: $viewMode is echoed into a JS string
// literal further down, so an unvalidated value is a reflected XSS vector.
$requestedViewMode = (string)($_GET['view_mode'] ?? 'own');
$viewMode = in_array($requestedViewMode, ['own', 'all'], true) ? $requestedViewMode : 'own';
$doctorId = $currentUser['id'];
$profileDoctorId = max(0, intval($_GET['doctor_id'] ?? 0));
$isDoctorPatientFilter = false;
$profileDoctor = null;

// A doctor profile may open a patient list scoped to that doctor.  Doctors may
// only request their own list; admin/staff users already have full clinic access.
if ($profileDoctorId > 0) {
    $db = Database::getInstance();
    $profileDoctor = $db->fetch(
        "SELECT id, full_name FROM users WHERE id = :id AND role = 'doctor'",
        ['id' => $profileDoctorId]
    );

    if (!$profileDoctor) {
        header('Location: ' . APP_URL . '/patients/index.php');
        exit;
    }

    if ($isDoctor && !$isAdmin && $profileDoctorId !== intval($currentUser['id'])) {
        header('Location: ' . APP_URL . '/403.php');
        exit;
    }

    $isDoctorPatientFilter = true;
    $viewMode = 'own';
}

if ($isDoctorPatientFilter) {
    $patients = $patientManager->getDoctorPatients($profileDoctorId, $page, ITEMS_PER_PAGE, $search);
    $totalPatients = $patientManager->countDoctorPatients($profileDoctorId, $search);
    $doctorStats = $patientManager->getDoctorPatientsStats($profileDoctorId);
} elseif ($isDoctor && !$isAdmin) {
    // Doctor view - default to own patients
    if ($viewMode === 'own') {
        $patients = $patientManager->getDoctorPatients($doctorId, $page, ITEMS_PER_PAGE, $search);
        $totalPatients = $patientManager->countDoctorPatients($doctorId, $search);
    } elseif (mb_strlen($search) >= DIRECTORY_SEARCH_MIN_CHARS) {
        // All clinic patients (restricted view) — search-only, see below
        $patients = $patientManager->getAllPatientsRestricted($doctorId, $page, ITEMS_PER_PAGE, $search);
        $totalPatients = $patientManager->countAllPatientsRestricted($doctorId, $search);
    } else {
        // Lazy directory: the clinic has thousands of patients and paging blindly
        // through colleagues' records is both slow and pointless — the doctor is
        // always looking for one known person. Nothing is loaded until they type
        // at least DIRECTORY_SEARCH_MIN_CHARS; the UI shows a "type to search"
        // prompt instead (see $directoryNeedsSearch below).
        $patients = [];
        $totalPatients = 0;
    }
    $doctorStats = $patientManager->getDoctorPatientsStats($doctorId);
} else {
    // Admin/Staff - see all patients
    $patients = $patientManager->getAll($page, ITEMS_PER_PAGE, $search);
    $totalPatients = $patientManager->count($search);
}
$totalPages = ceil($totalPatients / ITEMS_PER_PAGE);

// Get statistics (admin/staff or doctor's own stats)
if (!$search) {
    if ($isDoctorPatientFilter || ($isDoctor && !$isAdmin)) {
        // Doctor-specific stats
        $activeFoldersCount = $doctorStats['active_folders'] ?? 0;
        $closedFoldersCount = $doctorStats['closed_folders'] ?? 0;
        $patientsWithDebt = $doctorStats['patients_with_debt'] ?? 0;
        $totalDebtAmount = $doctorStats['total_debt'] ?? 0;
        $patientsWithAlerts = 0; // Not tracked per doctor
        $newPatientsThisMonth = $doctorStats['new_this_month'] ?? 0;
        $activeCases = $doctorStats['active_cases'] ?? 0;
    } else {
        // Global stats for admin/staff
        $activeFoldersCount = $patientManager->countActiveFolders();
        $closedFoldersCount = $patientManager->countClosedFolders();
        $patientsWithDebt = $patientManager->countPatientsWithDebt();
        $folderDebtAmount = $patientManager->getTotalDebtAmount();

        // Add X-ray debt to total
        $db = Database::getInstance();
        $xrayDebtSql = "SELECT COALESCE(SUM(final_amount), 0) as total FROM xray_requests WHERE status = 'completed' AND payment_status IN (" . XrayManager::debtStatusSqlList() . ")";
        $xrayDebtResult = $db->fetch($xrayDebtSql);
        $xrayDebt = $xrayDebtResult['total'] ?? 0;
        $totalDebtAmount = $folderDebtAmount + $xrayDebt;

        $patientsWithAlerts = $patientManager->countPatientsWithMedicalAlerts();
        $newPatientsThisMonth = $patientManager->countNewPatientsThisMonth();
        $activeCases = $patientManager->countActiveCases();
    }
}

$paginationContext = $profileDoctorId > 0 ? '&doctor_id=' . $profileDoctorId : '';

if (isset($_GET['deleted'])) {
    echo '<script>window.addEventListener("DOMContentLoaded", function() { showToast("success", "نەخۆشەکە سڕایەوە"); });</script>';
}

if (($_GET['delete_err'] ?? '') === 'pw') {
    echo '<script>window.addEventListener("DOMContentLoaded", function() { showToast("error", "وشەی نهێنی هەڵەیە — نەخۆشەکە نەسڕایەوە"); });</script>';
}

// Handle updated success message
if (isset($_GET['updated'])) {
    echo '<script>window.addEventListener("DOMContentLoaded", function() { showToast("success", "زانیاری نەخۆش بە سەرکەوتوویی نوێکرایەوە"); });</script>';
}
?>

<style>
/* ══════════════════════════════════════════════════════════════
   Patients list — compact Fluent layout
   Density is the point: one toolbar row, a slim stat strip and
   tight list rows, so roughly twice as many patients fit on screen.
   ══════════════════════════════════════════════════════════════ */
.pt-scope {
    /* style.css defines --win-accent / --status-error; there is no
       --primary-color or --danger-color anywhere, so referencing those
       silently yields an invalid value (white-on-white buttons). Everything
       below resolves through the real tokens with a hard fallback. */
    --pt-accent:  var(--win-accent, #0078D4);
    --pt-accent-d: var(--win-accent-pressed, #006CBD);
    --pt-danger:  var(--status-error, #C42B1C);
    --pt-line:    #e8eaed;
    --pt-line-2:  #dfe3e8;
    --pt-ink:     #1b1f24;
    --pt-ink-2:   #5c6773;
    --pt-ink-3:   #8a939e;
    --pt-bg:      #fff;
    --pt-tint:    #f6f8fa;
    --pt-r:       10px;
    --pt-r-sm:    8px;
    --pt-shadow:  0 1px 2px rgba(16, 24, 40, .04), 0 1px 3px rgba(16, 24, 40, .06);
    --pt-ring:    0 0 0 3px rgba(var(--win-accent-rgb, 0, 120, 212), .16);
}

/* ── Toolbar: search + filters + primary action in one row ── */
.pt-toolbar {
    background: var(--pt-bg);
    border: 1px solid var(--pt-line);
    border-radius: var(--pt-r);
    padding: 8px;
    margin-bottom: 10px;
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
    box-shadow: var(--pt-shadow);
}
.pt-search {
    position: relative;
    flex: 1 1 260px;
    min-width: 0;
    display: flex;
    align-items: center;
}
.pt-search > i {
    position: absolute;
    inset-inline-start: 12px;
    font-size: 13px;
    color: var(--pt-ink-3);
    pointer-events: none;
    transition: color .15s ease;
}
.pt-search input {
    width: 100%;
    border: 1px solid var(--pt-line-2);
    border-radius: var(--pt-r-sm);
    padding: 9px 36px 9px 12px;
    font-size: 13.5px;
    font-family: inherit;
    color: var(--pt-ink);
    background: var(--pt-tint);
    transition: background .15s ease, border-color .15s ease, box-shadow .15s ease;
}
.pt-search input::placeholder { color: var(--pt-ink-3); }
.pt-search input:focus {
    background: var(--pt-bg);
    border-color: var(--pt-accent);
    box-shadow: var(--pt-ring);
    outline: none;
}
.pt-search input:focus ~ i,
.pt-search:focus-within > i { color: var(--pt-accent); }
/* Search in flight: a thin indeterminate bar under the field, no layout shift. */
.pt-search input.searching { border-color: var(--pt-accent); }
.pt-search input.searching + .pt-progress { opacity: 1; }
.pt-progress {
    position: absolute;
    inset-inline: 1px;
    bottom: 1px;
    height: 2px;
    border-radius: 2px;
    overflow: hidden;
    opacity: 0;
    transition: opacity .15s ease;
    background: rgba(var(--win-accent-rgb, 0, 120, 212), .12);
}
.pt-progress::after {
    content: '';
    position: absolute;
    inset-block: 0;
    width: 40%;
    background: var(--pt-accent);
    border-radius: 2px;
    animation: ptSlide 1s ease-in-out infinite;
}
@keyframes ptSlide {
    0%   { inset-inline-start: -40%; }
    100% { inset-inline-start: 100%; }
}

/* Toolbar buttons — compact, quiet until hovered */
.pt-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    height: 36px;
    padding: 0 13px;
    border-radius: var(--pt-r-sm);
    border: 1px solid var(--pt-line-2);
    background: var(--pt-bg);
    color: var(--pt-ink-2);
    font: 600 13px/1 inherit;
    font-family: inherit;
    cursor: pointer;
    white-space: nowrap;
    text-decoration: none;
    transition: background .15s ease, border-color .15s ease, color .15s ease, box-shadow .15s ease;
}
.pt-btn:hover { background: var(--pt-tint); border-color: #cdd3da; color: var(--pt-ink); }
.pt-btn:focus-visible { outline: none; box-shadow: var(--pt-ring); }
.pt-btn i { font-size: 12px; }
.pt-btn--primary {
    background: var(--pt-accent);
    border-color: var(--pt-accent);
    color: #fff;
}
.pt-btn--primary:hover { background: #006cbe; border-color: #006cbe; color: #fff; }
.pt-btn--icon { padding: 0 10px; }

#toggleFilters.has-active-filters {
    background: rgba(var(--win-accent-rgb, 0, 120, 212), .1);
    border-color: rgba(var(--win-accent-rgb, 0, 120, 212), .45);
    color: var(--pt-accent);
}

/* ── Segmented control (doctor view mode) ── */
.pt-seg {
    display: inline-flex;
    padding: 3px;
    gap: 2px;
    background: var(--pt-tint);
    border: 1px solid var(--pt-line);
    border-radius: var(--pt-r-sm);
}
.view-mode-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    height: 30px;
    padding: 0 12px;
    border: 0;
    border-radius: 6px;
    background: transparent;
    color: var(--pt-ink-2);
    font: 600 12.5px/1 inherit;
    font-family: inherit;
    cursor: pointer;
    white-space: nowrap;
    transition: background .15s ease, color .15s ease, box-shadow .15s ease;
}
.view-mode-btn:hover { color: var(--pt-ink); }
.view-mode-btn i { font-size: 12px; opacity: .85; }
.view-mode-btn.active {
    background: var(--pt-bg);
    color: var(--pt-accent);
    box-shadow: var(--pt-shadow);
}
.view-mode-btn .pt-count {
    font-size: 11px;
    font-weight: 700;
    padding: 1px 6px;
    border-radius: 20px;
    background: rgba(0, 0, 0, .06);
    color: inherit;
}
.view-mode-btn.active .pt-count { background: rgba(var(--win-accent-rgb, 0, 120, 212), .12); }

/* Context strip: doctor-profile scope / restricted-view notice */
.pt-note {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    padding: 7px 12px;
    margin-bottom: 10px;
    border-radius: var(--pt-r-sm);
    font-size: 12.5px;
    font-weight: 600;
    border: 1px solid transparent;
}
.pt-note i { font-size: 12px; }
.pt-note--info   { background: #eef6fd; border-color: #cfe4f7; color: #10538f; }
.pt-note--warn   { background: #fff6e8; border-color: #f7dfba; color: #8a5300; }
.pt-note--purple { background: #f6effa; border-color: #e3d2ee; color: #6a3592; }
.pt-note .pt-spacer { margin-inline-start: auto; }

/* ── Stat strip: same grid contract, far less height ── */
.stats-grid-patients {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(132px, 1fr));
    gap: 6px;
    margin-bottom: 10px;
}
.stat-card-patient {
    position: relative;
    background: var(--pt-bg);
    border: 1px solid var(--pt-line);
    border-radius: var(--pt-r-sm);
    padding: 8px 10px;
    transition: border-color .15s ease, box-shadow .15s ease, background .15s ease;
    overflow: hidden;
}
.stat-card-patient[data-filter] { cursor: pointer; user-select: none; }
.stat-card-patient[data-filter]:hover {
    border-color: #c4d7ea;
    box-shadow: var(--pt-shadow);
    background: var(--pt-tint);
}
.stat-card-patient.active-filter {
    border-color: var(--pt-accent);
    background: rgba(var(--win-accent-rgb, 0, 120, 212), .05);
}
/* Accent rail marks the active facet without shifting anything. */
.stat-card-patient.active-filter::before {
    content: '';
    position: absolute;
    inset-block: 0;
    inset-inline-start: 0;
    width: 3px;
    background: var(--pt-accent);
}
.stat-card-patient h4 {
    font-size: 11px;
    color: var(--pt-ink-2);
    margin: 0 0 3px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 5px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.stat-card-patient h4 i { flex-shrink: 0; font-size: 10px; opacity: .7; }
.stat-card-patient .stat-value {
    font-size: 19px;
    font-weight: 700;
    color: var(--pt-ink);
    line-height: 1.15;
    letter-spacing: -.01em;
    font-variant-numeric: tabular-nums;
}
/* Tone lives on the icon; the number stays near-black so the row of figures
   reads as one scale instead of seven competing colours. */
.stat-card-patient[data-tone="blue"]  h4 i { color: #0b5da8; }
.stat-card-patient[data-tone="cyan"]  h4 i { color: #0e7490; }
.stat-card-patient[data-tone="green"] h4 i { color: #147c47; }
.stat-card-patient[data-tone="grey"]  h4 i { color: #8a939e; }
.stat-card-patient[data-tone="amber"] h4 i { color: #a35b00; }
.stat-card-patient[data-tone="pink"]  h4 i { color: #b0286b; }
.stat-card-patient[data-tone="red"]   h4 i { color: #c0392b; }
.stat-card-patient[data-tone="red"]   .stat-value,
.stat-card-patient[data-tone="pink"]  .stat-value { color: #b3261e; }

/* ── Panel + loader ── */
.pt-panel {
    background: var(--pt-bg);
    border: 1px solid var(--pt-line);
    border-radius: var(--pt-r);
    box-shadow: var(--pt-shadow);
}
.pt-panel .panel-body { padding: 10px; }
#loadingIndicator { text-align: center; padding: 28px; }
#loadingIndicator i { font-size: 26px; color: var(--pt-accent); }
#loadingIndicator p { margin-top: 10px; font-size: 12.5px; font-weight: 600; color: var(--pt-ink-2); }

/* ── Patient rows ── */
.patient-card {
    background: var(--pt-bg);
    border: 1px solid var(--pt-line);
    border-radius: var(--pt-r-sm);
    padding: 8px 10px;
    margin-bottom: 6px;
    display: grid;
    grid-template-columns: 36px minmax(0, 1fr) auto;
    gap: 10px;
    align-items: center;
    transition: border-color .15s ease, box-shadow .15s ease;
}
.patient-card:hover {
    border-color: #c4d7ea;
    box-shadow: var(--pt-shadow);
}
.patient-card.has-alert { border-inline-start: 3px solid var(--pt-danger); }
/* Restricted rows (another doctor's patient) carry an amber rail — set here
   rather than inline in ajax_search.php so both renderers stay in sync. */
.patient-card.restricted-view { border-inline-start: 3px solid #e8a33d; }
.patient-avatar {
    width: 36px;
    height: 36px;
    border-radius: 9px;
    background: #e8f1fb;
    color: #0b5da8;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 15px;
    font-weight: 700;
}
.patient-avatar.female { background: #fdecf3; color: #b0286b; }
.patient-info { min-width: 0; }
.patient-name {
    font-size: 13.5px;
    font-weight: 600;
    color: var(--pt-ink);
    margin-bottom: 2px;
    display: flex;
    align-items: center;
    gap: 6px;
    line-height: 1.35;
}
.patient-name a { color: inherit; text-decoration: none; }
.patient-name a:hover { color: var(--pt-accent); }
.patient-meta {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    font-size: 12px;
    color: var(--pt-ink-2);
}
.patient-meta-item { display: flex; align-items: center; gap: 4px; min-width: 0; }
.patient-meta-item i { color: var(--pt-ink-3); font-size: 10.5px; }
.patient-meta-item a { color: inherit; text-decoration: none; }
.patient-meta-item a:hover { color: var(--pt-accent); }
.patient-actions { display: flex; gap: 5px; flex-shrink: 0; }
/* Row actions stay quiet until the row is hovered, so the list reads calmly. */
.patient-actions .btn {
    width: 30px;
    height: 30px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    border-radius: 7px;
    border: 1px solid var(--pt-line-2);
    background: var(--pt-bg);
    color: var(--pt-ink-2);
    transition: background .15s ease, color .15s ease, border-color .15s ease;
}
.patient-card:hover .patient-actions .btn { border-color: #cdd3da; }
.patient-actions .btn:hover { background: var(--pt-accent); border-color: var(--pt-accent); color: #fff; }
.patient-actions .btn.btn-danger:hover { background: var(--pt-danger); border-color: var(--pt-danger); }

/* ── Badges ── */
.badge {
    font-size: 10.5px;
    padding: 1px 7px;
    border-radius: 20px;
    font-weight: 600;
    line-height: 1.6;
}
.badge-blue   { background: #e8f1fb; color: #0b5da8; }
.badge-pink   { background: #fdecf3; color: #b0286b; }
/* Restricted-view marker on AJAX-rendered cards (ajax_search.php). */
.badge-orange { background: #fff3e0; color: #a35b00; }
.badge-orange i { font-size: 9px; }

/* ── Advanced filters ── */
#advancedFilters { animation: ptDrop .18s ease; }
@keyframes ptDrop {
    from { opacity: 0; transform: translateY(-4px); }
    to   { opacity: 1; transform: translateY(0); }
}
.pt-filters {
    background: var(--pt-bg);
    border: 1px solid var(--pt-line);
    border-radius: var(--pt-r);
    padding: 12px 14px;
    margin-bottom: 10px;
    box-shadow: var(--pt-shadow);
}
.filters-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}
.filters-title {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--pt-ink);
    display: flex;
    align-items: center;
    gap: 7px;
}
.filters-title i { color: var(--pt-ink-3); font-size: 12px; }
.active-filter-badge {
    background: var(--pt-accent);
    color: #fff;
    padding: 1px 7px;
    border-radius: 20px;
    font-size: 10.5px;
    font-weight: 700;
    min-width: 18px;
    text-align: center;
}
.pt-filter-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 10px;
}
.filter-group { position: relative; min-width: 0; }
.filter-label {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    font-weight: 600;
    margin-bottom: 4px;
    color: var(--pt-ink-2);
}
.filter-label i { color: var(--pt-ink-3); font-size: 11px; }
.filter-control {
    width: 100%;
    padding: 7px 10px;
    font-size: 12.5px;
    border: 1px solid var(--pt-line-2);
    border-radius: 7px;
    background: var(--pt-bg);
    color: var(--pt-ink);
    font-family: inherit;
    transition: border-color .15s ease, box-shadow .15s ease;
}
.filter-control:hover { border-color: #cdd3da; }
.filter-control:focus {
    border-color: var(--pt-accent);
    box-shadow: var(--pt-ring);
    outline: none;
}
.filter-control.active { border-color: var(--pt-accent); background: rgba(var(--win-accent-rgb, 0, 120, 212), .04); }
.filter-group.has-value .filter-label { color: var(--pt-accent); }
.filter-group.has-value .filter-label i { color: var(--pt-accent); }

/* ── Pagination ── */
.pagination {
    display: flex;
    gap: 4px;
    justify-content: center;
    margin-top: 14px;
    padding-top: 12px;
    border-top: 1px solid var(--pt-line);
}
.pagination-btn {
    min-width: 32px;
    height: 32px;
    padding: 0 9px;
    border: 1px solid var(--pt-line-2);
    border-radius: 7px;
    background: var(--pt-bg);
    color: var(--pt-ink-2);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 12.5px;
    font-weight: 600;
    text-decoration: none;
    transition: background .15s ease, color .15s ease, border-color .15s ease;
    font-variant-numeric: tabular-nums;
}
.pagination-btn:hover:not(.disabled) { background: var(--pt-tint); border-color: #cdd3da; color: var(--pt-ink); }
.pagination-btn.active {
    background: var(--pt-accent);
    color: #fff;
    border-color: var(--pt-accent);
}
.pagination-btn.disabled { opacity: .4; cursor: not-allowed; }

/* ── Empty state ── */
.empty-state { text-align: center; padding: 32px 16px; }
.empty-state-icon { font-size: 34px; color: var(--pt-ink-3); opacity: .5; }
.empty-state-title { font-size: 14px; font-weight: 700; color: var(--pt-ink); margin: 12px 0 4px; }
.empty-state-text { font-size: 12.5px; color: var(--pt-ink-2); margin-bottom: 14px; }

/* ── Small screens: keep it usable, not cramped ── */
@media (max-width: 640px) {
    .patient-card { grid-template-columns: 32px minmax(0, 1fr); gap: 8px; }
    .patient-avatar { width: 32px; height: 32px; font-size: 13px; }
    .patient-actions { grid-column: 1 / -1; justify-content: flex-end; }
    .stats-grid-patients { grid-template-columns: repeat(auto-fit, minmax(108px, 1fr)); }
    .pt-toolbar > .pt-btn { flex: 1 1 auto; justify-content: center; }
    .pt-seg { width: 100%; }
    .view-mode-btn { flex: 1; justify-content: center; }
}
@media (prefers-reduced-motion: reduce) {
    .pt-progress::after { animation: none; }
    #advancedFilters { animation: none; }
}
</style>

<div class="pt-scope">

<?php if ($isDoctorPatientFilter): ?>
<div class="pt-note pt-note--purple">
    <i class="fas fa-user-md"></i>
    <span>نەخۆشەکانی د. <?= htmlspecialchars($profileDoctor['full_name']) ?></span>
    <a href="<?= APP_URL ?>/doctors/profile.php?id=<?= $profileDoctorId ?>" class="pt-btn pt-spacer">
        <i class="fas fa-id-card"></i> پرۆفایلی پزیشک
    </a>
</div>
<?php endif; ?>

<?php if ($isDoctor && !$isAdmin && $viewMode === 'all'): ?>
<div class="pt-note pt-note--warn">
    <i class="fas fa-lock"></i>
    <span>بینینی سنوورمەند — تەنها زانیاری بنەڕەتی و ئێکسڕەی دەبینیت، نەک کاری پزیشکانی تر</span>
</div>
<?php endif; ?>

<!-- Toolbar: view mode + search + filters + add, all in one row -->
<div class="pt-toolbar">
    <?php if ($isDoctor && !$isAdmin && !$isDoctorPatientFilter): ?>
    <div class="pt-seg" role="tablist" aria-label="بینینی نەخۆشەکان">
        <button type="button" class="view-mode-btn <?= $viewMode === 'own' ? 'active' : '' ?>" data-mode="own"
                role="tab" aria-selected="<?= $viewMode === 'own' ? 'true' : 'false' ?>">
            <i class="fas fa-user-check"></i> نەخۆشەکانم
            <span class="pt-count"><?= number_format($patientManager->countDoctorPatients($doctorId)) ?></span>
        </button>
        <button type="button" class="view-mode-btn <?= $viewMode === 'all' ? 'active' : '' ?>" data-mode="all"
                role="tab" aria-selected="<?= $viewMode === 'all' ? 'true' : 'false' ?>">
            <i class="fas fa-users"></i> نەخۆشەکانی تر
            <span class="pt-count"><?= number_format($patientManager->countAllPatientsRestricted($doctorId)) ?></span>
        </button>
    </div>
    <?php endif; ?>

    <div class="pt-search">
        <input type="text" id="searchInput"
               placeholder="<?= ($isDoctor && !$isAdmin && $viewMode === 'all') ? 'گەڕان لە نەخۆشەکانی تری کلینیک...' : 'گەڕان بە ناو، کۆد، یان ژمارە تەلەفۆن...' ?>"
               value="<?= htmlspecialchars($search) ?>" autocomplete="off" aria-label="گەڕان">
        <div class="pt-progress" aria-hidden="true"></div>
        <i class="fas fa-search"></i>
    </div>

    <button type="button" class="pt-btn pt-btn--primary pt-btn--icon" id="searchBtn" title="گەڕان" aria-label="گەڕان">
        <i class="fas fa-search"></i>
    </button>
    <?php /* Rendered in every mode: updateToggleButtonText() dereferences it
             unconditionally, so hiding it used to throw a TypeError that killed
             search entirely on the doctor "other patients" tab. */ ?>
    <button type="button" class="pt-btn" id="toggleFilters">
        <i class="fas fa-filter"></i> فلتەر
    </button>
    <button type="button" class="pt-btn pt-btn--icon" id="clearBtn" style="display: none;" title="پاککردنەوە" aria-label="پاککردنەوە">
        <i class="fas fa-times"></i>
    </button>
    <a href="<?= APP_URL ?>/patients/add.php" class="pt-btn pt-btn--primary">
        <i class="fas fa-plus"></i> نەخۆشی نوێ
    </a>
</div>

<!-- Advanced Filters Panel -->
<div id="advancedFilters" class="pt-filters" style="display: none;">
    <div class="filters-header">
        <div class="filters-title">
            <i class="fas fa-sliders-h"></i>
            فلتەرە پێشکەوتووەکان
            <span class="active-filter-badge" id="activeFilterCount" style="display: none;">0</span>
        </div>
        <button type="button" class="pt-btn" id="quickClearFilters" style="display: none; height: 28px; font-size: 12px;">
            <i class="fas fa-eraser"></i> پاککردنەوە
        </button>
    </div>
    <div class="pt-filter-grid">
        <div class="filter-group">
            <label class="filter-label">
                <i class="fas fa-venus-mars"></i>
                ڕەگەز
            </label>
            <select id="filterGender" class="filter-control">
                <option value="">هەمووی</option>
                <option value="male">نێر</option>
                <option value="female">مێ</option>
            </select>
        </div>
        <div class="filter-group">
            <label class="filter-label">
                <i class="fas fa-calendar-day"></i>
                لە بەروار
            </label>
            <input type="date" id="filterDateFrom" class="filter-control">
        </div>
        <div class="filter-group">
            <label class="filter-label">
                <i class="fas fa-calendar-check"></i>
                بۆ بەروار
            </label>
            <input type="date" id="filterDateTo" class="filter-control">
        </div>
        <div class="filter-group">
            <label class="filter-label">
                <i class="fas fa-folder-open"></i>
                دۆخی دۆسیە
            </label>
            <select id="filterFolderStatus" class="filter-control">
                <option value="">هەمووی</option>
                <option value="active">کراوە</option>
                <option value="closed">داخراو</option>
            </select>
        </div>
        <div class="filter-group">
            <label class="filter-label">
                <i class="fas fa-dollar-sign"></i>
                قەرز
            </label>
            <select id="filterHasDebt" class="filter-control">
                <option value="">هەمووی</option>
                <option value="1">بە قەرز</option>
                <option value="0">بەبێ قەرز</option>
            </select>
        </div>
        <div class="filter-group">
            <label class="filter-label">
                <i class="fas fa-exclamation-triangle"></i>
                ئاگاداری پزیشکی
            </label>
            <select id="filterMedicalAlerts" class="filter-control">
                <option value="">هەمووی</option>
                <option value="1">بە ئاگاداری</option>
                <option value="0">بەبێ ئاگاداری</option>
            </select>
        </div>
    </div>
</div>

<!-- Stats -->
<div class="stats-grid-patients" id="statsContainer" style="<?= $search ? 'display: none;' : '' ?>">
    <div class="stat-card-patient" data-filter="all" data-tone="blue" title="هەموو نەخۆشەکان">
        <h4><i class="fas fa-users"></i> کۆی نەخۆشەکان</h4>
        <div class="stat-value" id="statTotalPatients"><?= $search ? '0' : number_format($totalPatients) ?></div>
    </div>
    <div class="stat-card-patient" data-filter="new_month" data-tone="cyan" title="نەخۆشی نوێ ئەم مانگە">
        <h4><i class="fas fa-user-plus"></i> نوێ ئەم مانگە</h4>
        <div class="stat-value" id="statNewThisMonth"><?= $search ? '0' : number_format($newPatientsThisMonth) ?></div>
    </div>
    <div class="stat-card-patient" data-filter="active_folders" data-tone="green" title="نەخۆشانی دۆسیەی کراوە">
        <h4><i class="fas fa-folder-open"></i> دۆسیەی کراوە</h4>
        <div class="stat-value" id="statActiveFolders"><?= $search ? '0' : number_format($activeFoldersCount) ?></div>
    </div>
    <div class="stat-card-patient" data-filter="closed_folders" data-tone="grey" title="نەخۆشانی دۆسیەی داخراو">
        <h4><i class="fas fa-folder"></i> دۆسیەی داخراو</h4>
        <div class="stat-value" id="statClosedFolders"><?= $search ? '0' : number_format($closedFoldersCount) ?></div>
    </div>
    <div class="stat-card-patient" data-filter="active_cases" data-tone="amber" title="نەخۆشانی کەیسی کراوە">
        <h4><i class="fas fa-briefcase-medical"></i> کەیسی کراوە</h4>
        <div class="stat-value" id="statActiveCases"><?= $search ? '0' : number_format($activeCases) ?></div>
    </div>
    <div class="stat-card-patient" data-filter="has_debt" data-tone="pink" title="نەخۆشانی بە قەرز">
        <h4><i class="fas fa-hand-holding-usd"></i> نەخۆش بە قەرز</h4>
        <div class="stat-value" id="statPatientsWithDebt"><?= $search ? '0' : number_format($patientsWithDebt) ?></div>
    </div>
    <div class="stat-card-patient" data-filter="has_debt" data-tone="red" title="نەخۆشانی بە قەرز">
        <h4><i class="fas fa-money-bill-wave"></i> کۆی پارەی قەرز</h4>
        <div class="stat-value" id="statTotalDebt"><?= $search ? '0' : number_format($totalDebtAmount) ?> <small style="font-size: 14px;"></small></div>
    </div>
</div>

<!-- Loading Indicator -->
<div id="loadingIndicator" style="display: none;">
    <i class="fas fa-circle-notch fa-spin"></i>
    <p>چاوەڕوان بە...</p>
</div>

<div class="panel pt-panel" id="patientsPanel">
    <div class="panel-body">
        <!-- Patient Cards -->
        <div id="patientsList">
        <?php
        // The clinic-wide directory loads nothing until the doctor types — show
        // the search prompt rather than a misleading "no patients found".
        $directoryNeedsSearch = $isDoctor && !$isAdmin && !$isDoctorPatientFilter
            && $viewMode === 'all' && mb_strlen($search) < DIRECTORY_SEARCH_MIN_CHARS;
        ?>
        <?php if ($directoryNeedsSearch): ?>
        <div class="empty-state">
            <i class="fas fa-search empty-state-icon"></i>
            <p class="empty-state-title">گەڕان بۆ نەخۆشێکی کلینیک</p>
            <p class="empty-state-text">
                لانیکەم <?= DIRECTORY_SEARCH_MIN_CHARS ?> پیت بنووسە بە ناو، ژمارەی مۆبایل، یان کۆدی نەخۆش
            </p>
        </div>
        <?php elseif (empty($patients)): ?>
        <div class="empty-state">
            <i class="fas fa-users empty-state-icon"></i>
            <p class="empty-state-title">هیچ نەخۆشێک نەدۆزرایەوە</p>
            <p class="empty-state-text">
                <?= $search ? 'گەڕانەکەت ئەنجامی نەبوو' : 'هێشتا هیچ نەخۆشێک تۆمار نەکراوە' ?>
            </p>
            <a href="<?= APP_URL ?>/patients/add.php" class="pt-btn pt-btn--primary">
                <i class="fas fa-plus"></i> زیادکردنی نەخۆش
            </a>
        </div>
        <?php else: ?>
            <?php foreach ($patients as $patient): ?>
            <?php
            // Check for medical conditions
            $hasConditions = $patientManager->hasMedicalAlert($patient['id']);
            // Get first letter of name for avatar
            $firstLetter = mb_substr($patient['full_name'], 0, 1, 'UTF-8');
            ?>
            <div class="patient-card <?= $hasConditions ? 'has-alert' : '' ?>">
                <!-- Avatar -->
                <a href="<?= APP_URL ?>/patients/view.php?id=<?= $patient['id'] ?>">
                    <div class="patient-avatar <?= $patient['gender'] === 'female' ? 'female' : '' ?>">
                        <?= htmlspecialchars($firstLetter) ?>
                    </div>
                </a>

                <!-- Patient Info -->
                <div class="patient-info">
                    <div class="patient-name">
                        <a href="<?= APP_URL ?>/patients/view.php?id=<?= $patient['id'] ?>">
                            <?= htmlspecialchars($patient['full_name']) ?>
                        </a>
                        <?php if ($hasConditions): ?>
                        <i class="fas fa-exclamation-triangle" style="color: var(--pt-danger); font-size: 13px;" title="ئاگادارکردنەوەی پزیشکی"></i>
                        <?php endif; ?>
                        <span class="badge <?= $patient['gender'] === 'male' ? 'badge-blue' : 'badge-pink' ?>">
                            <?= $patient['gender'] === 'male' ? 'نێر' : 'مێ' ?>
                        </span>
                    </div>
                    <div class="patient-meta">
                        <div class="patient-meta-item">
                            <i class="fas fa-id-card"></i>
                            <span><?= $patient['patient_code'] ?></span>
                        </div>
                        <div class="patient-meta-item">
                            <i class="fas fa-phone"></i>
                            <a href="tel:<?= $patient['phone'] ?>" style="color: inherit;"><?= $patient['phone'] ?></a>
                        </div>
                        <div class="patient-meta-item">
                            <i class="fas fa-folder"></i>
                            <span><?= $patient['folder_count'] ?? 0 ?> دۆسیە</span>
                        </div>
                        <?php if (($patient['total_debt'] ?? 0) > 0): ?>
                        <div class="patient-meta-item" style="color: var(--pt-danger); font-weight: 600;">
                            <i class="fas fa-exclamation-circle"></i>
                            <span>قەرز: <?= number_format($patient['total_debt']) ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="patient-meta-item">
                            <i class="fas fa-calendar"></i>
                            <span><?= date('Y-m-d', strtotime($patient['created_at'])) ?></span>
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div class="patient-actions">
                    <a href="<?= APP_URL ?>/patients/view.php?id=<?= $patient['id'] ?>"
                       class="btn btn-primary" title="بینین">
                        <i class="fas fa-eye"></i>
                    </a>
                    <a href="<?= APP_URL ?>/patients/edit.php?id=<?= $patient['id'] ?>"
                       class="btn btn-secondary" title="دەستکاری">
                        <i class="fas fa-edit"></i>
                    </a>
                    <a href="<?= APP_URL ?>/patients/statement.php?patient_id=<?= $patient['id'] ?>"
                       class="btn btn-secondary" title="کشف حساب" style="color:#0878d1;border-color:#0878d1;">
                        <i class="fas fa-file-invoice-dollar"></i>
                    </a>
                    <?php
                    // Every role may delete; a restricted doctor only sees the button
                    // for patients they already have access to (server re-checks anyway).
                    $canDeleteThis = !AccessGuard::isRestrictedDoctor()
                        || AccessGuard::doctorCanViewPatient((int)$patient['id']);
                    if ($canDeleteThis):
                    ?>
                    <button type="button"
                            onclick="deletePatient(<?= (int)$patient['id'] ?>, <?= htmlspecialchars(json_encode($patient['full_name'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES) ?>)"
                            class="btn btn-danger" title="سڕینەوە">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
        </div><!-- End patientsList -->

        <!-- One shared delete form; deletePatient() fills it in after re-auth -->
        <form id="deletePatientForm" method="POST" action="<?= APP_URL ?>/patients/index.php" style="display:none;">
            <input type="hidden" name="action" value="delete_patient">
            <input type="hidden" name="patient_id" value="">
            <input type="hidden" name="confirm_password" value="">
            <?= Csrf::field() ?>
        </form>
        <script>
        // Deleting a patient requires the password again, even in a live session.
        // The password is only ever posted, never stored client-side.
        function deletePatient(id, name) {
            Swal.fire({
                title: 'سڕینەوەی نەخۆش',
                html: '<div style="line-height:1.9;">'
                    + '<b style="font-size:15px;">' + name.replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])) + '</b><br>'
                    + 'زانیاری نەخۆش دەشاردرێتەوە، بەڵام مێژووی دارایی و کەیسەکان دەمێننەوە.<br>'
                    + '<span style="color:#C42B1C;">بۆ دڵنیابوون، وشەی نهێنی خۆت بنووسە:</span>'
                    + '</div>',
                icon: 'warning',
                input: 'password',
                inputPlaceholder: 'وشەی نهێنی',
                inputAttributes: { autocomplete: 'current-password' },
                showCancelButton: true,
                confirmButtonColor: '#C42B1C',
                cancelButtonColor: '#5C5C5C',
                confirmButtonText: 'سڕینەوە',
                cancelButtonText: 'نەخێر',
                preConfirm: function (pw) {
                    if (!pw) { Swal.showValidationMessage('وشەی نهێنی پێویستە'); return false; }
                    return pw;
                }
            }).then(function (r) {
                if (!r.isConfirmed || !r.value) return;
                var f = document.getElementById('deletePatientForm');
                f.querySelector('[name="patient_id"]').value = id;
                f.querySelector('[name="confirm_password"]').value = r.value;
                f.submit();
            });
        }
        </script>

        <!-- Pagination -->
        <div id="paginationContainer">
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <a href="?page=1<?= $search ? '&search=' . urlencode($search) : '' ?><?= $paginationContext ?>"
               class="pagination-btn <?= $page <= 1 ? 'disabled' : '' ?>" <?= $page <= 1 ? 'disabled' : '' ?>>
                <i class="fas fa-angle-double-right"></i>
            </a>
            <a href="?page=<?= $page - 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $paginationContext ?>"
               class="pagination-btn <?= $page <= 1 ? 'disabled' : '' ?>" <?= $page <= 1 ? 'disabled' : '' ?>>
                <i class="fas fa-angle-right"></i>
            </a>

            <?php
            $start = max(1, $page - 2);
            $end = min($totalPages, $page + 2);
            for ($i = $start; $i <= $end; $i++):
            ?>
            <a href="?page=<?= $i ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $paginationContext ?>"
               class="pagination-btn <?= $i === $page ? 'active' : '' ?>">
                <?= $i ?>
            </a>
            <?php endfor; ?>

            <a href="?page=<?= $page + 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $paginationContext ?>"
               class="pagination-btn <?= $page >= $totalPages ? 'disabled' : '' ?>" <?= $page >= $totalPages ? 'disabled' : '' ?>>
                <i class="fas fa-angle-left"></i>
            </a>
            <a href="?page=<?= $totalPages ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $paginationContext ?>"
               class="pagination-btn <?= $page >= $totalPages ? 'disabled' : '' ?>" <?= $page >= $totalPages ? 'disabled' : '' ?>>
                <i class="fas fa-angle-double-left"></i>
            </a>
        </div>
        <?php endif; ?>
        </div><!-- End pagination container -->
    </div>
</div>

</div><!-- End .pt-scope -->

<script>
// AJAX Patient Search
let currentPage = <?= $page ?>;
let searchTimeout = null;
let searchCache = new Map(); // Result cache for performance
const CACHE_DURATION = 60000; // Cache results for 60 seconds
let currentViewMode = <?= json_encode($viewMode, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>; // Doctor view mode
const profileDoctorId = <?= $profileDoctorId ?>;
const isDoctor = <?= ($isDoctor && !$isAdmin) ? 'true' : 'false' ?>;

// View mode toggle for doctors
<?php if ($isDoctor && !$isAdmin && !$isDoctorPatientFilter): ?>
document.querySelectorAll('.view-mode-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const mode = this.getAttribute('data-mode');
        if (mode !== currentViewMode) {
            currentViewMode = mode;
            // Clear cache when switching modes
            searchCache.clear();
            // Reload page with new mode
            window.location.href = '<?= APP_URL ?>/patients/index.php?view_mode=' + mode;
        }
    });
});
<?php endif; ?>

// Toggle filters panel
const toggleFiltersBtn = document.getElementById('toggleFilters');
if (toggleFiltersBtn) {
    toggleFiltersBtn.addEventListener('click', function() {
        const filtersPanel = document.getElementById('advancedFilters');
        const isVisible = filtersPanel.style.display !== 'none';
        filtersPanel.style.display = isVisible ? 'none' : 'block';

        // Update button text based on active filters
        updateToggleButtonText();
    });
}

// Update toggle button text with filter count
function updateToggleButtonText() {
    const toggleBtn = document.getElementById('toggleFilters');
    const filtersPanel = document.getElementById('advancedFilters');
    const badge = document.getElementById('activeFilterCount');
    // Defensive: this runs on every keystroke via performSearch(). A missing
    // element must never throw, or the search request is never sent.
    if (!toggleBtn || !filtersPanel || !badge) return;
    const isVisible = filtersPanel.style.display !== 'none';
    const count = parseInt(badge.textContent, 10) || 0;

    if (isVisible) {
        toggleBtn.innerHTML = '<i class="fas fa-times"></i> شاردنەوەی فلتەر';
    } else {
        if (count && count > 0) {
            toggleBtn.innerHTML = `<i class="fas fa-filter"></i> فلتەرەکان (${count})`;
            toggleBtn.classList.add('has-active-filters');
        } else {
            toggleBtn.innerHTML = '<i class="fas fa-filter"></i> فلتەرە پێشکەوتووەکان';
            toggleBtn.classList.remove('has-active-filters');
        }
    }
}

// Update active filter count and visual feedback
function updateActiveFilterCount() {
    const searchEl = document.getElementById('searchInput');
    const searchValue = searchEl ? searchEl.value.trim() : '';

    // Only count advanced filters (not search box) for button styling
    const filterIds = ['filterGender', 'filterDateFrom', 'filterDateTo',
                       'filterFolderStatus', 'filterHasDebt', 'filterMedicalAlerts'];
    const advancedFilters = {};
    filterIds.forEach(id => {
        const el = document.getElementById(id);
        advancedFilters[id] = el ? el.value : '';
    });

    let count = 0;

    // Update visual feedback for advanced filters only
    Object.keys(advancedFilters).forEach(key => {
        const element = document.getElementById(key);
        if (!element) return;
        const filterGroup = element.closest('.filter-group');

        if (advancedFilters[key]) {
            count++;
            element.classList.add('active');
            if (filterGroup) filterGroup.classList.add('has-value');
        } else {
            element.classList.remove('active');
            if (filterGroup) filterGroup.classList.remove('has-value');
        }
    });

    const badge = document.getElementById('activeFilterCount');
    const quickClear = document.getElementById('quickClearFilters');

    if (badge) {
        badge.textContent = count;
        badge.style.display = count > 0 ? 'inline-block' : 'none';
    }
    if (quickClear) {
        quickClear.style.display = count > 0 ? 'inline-flex' : 'none';
    }

    // Update toggle button text
    updateToggleButtonText();

    // Return true if either search or filters are active (for clear button)
    return (searchValue.length > 0 || count > 0);
}

// Generate cache key from search parameters
function getCacheKey(page, searchInput, filters) {
    return `${currentViewMode}|${page}|${searchInput}|${filters.gender}|${filters.dateFrom}|${filters.dateTo}|${filters.folderStatus}|${filters.hasDebt}|${filters.medicalAlerts}`;
}

// Clean expired cache entries
function cleanExpiredCache() {
    const now = Date.now();
    for (let [key, value] of searchCache.entries()) {
        if (now - value.timestamp > CACHE_DURATION) {
            searchCache.delete(key);
        }
    }
}

// Perform search
function performSearch(page = 1) {
    const searchInputEl = document.getElementById('searchInput');
    const searchInput = searchInputEl.value.trim();
    const filterGender = document.getElementById('filterGender').value;
    const filterDateFrom = document.getElementById('filterDateFrom').value;
    const filterDateTo = document.getElementById('filterDateTo').value;
    const filterFolderStatus = document.getElementById('filterFolderStatus').value;
    const filterHasDebt = document.getElementById('filterHasDebt').value;
    const filterMedicalAlerts = document.getElementById('filterMedicalAlerts').value;

    // Update active filter count and show/hide clear buttons
    const hasFilters = updateActiveFilterCount();
    document.getElementById('clearBtn').style.display = hasFilters ? 'inline-flex' : 'none';

    // Create cache key
    const filters = {
        gender: filterGender,
        dateFrom: filterDateFrom,
        dateTo: filterDateTo,
        folderStatus: filterFolderStatus,
        hasDebt: filterHasDebt,
        medicalAlerts: filterMedicalAlerts
    };
    const cacheKey = getCacheKey(page, searchInput, filters);

    // Check cache first for instant results
    cleanExpiredCache();
    if (searchCache.has(cacheKey)) {
        const cachedData = searchCache.get(cacheKey);
        // Use cached data immediately for instant display
        updateUIWithResults(cachedData.data, hasFilters);
        return;
    }

    // Add searching visual feedback
    searchInputEl.classList.add('searching');

    // Show loading indicator with optimized transition
    document.getElementById('loadingIndicator').style.display = 'block';
    document.getElementById('patientsPanel').style.opacity = '0.6';
    document.getElementById('patientsPanel').style.transform = 'scale(0.98)';
    document.getElementById('patientsPanel').style.transition = 'all 0.15s ease';

    // Prepare form data
    const formData = new FormData();
    formData.append('page', page);
    formData.append('search', searchInput);
    formData.append('view_mode', currentViewMode); // Include doctor view mode
    if (profileDoctorId > 0) formData.append('doctor_id', profileDoctorId);
    if (filterGender) formData.append('gender', filterGender);
    if (filterDateFrom) formData.append('date_from', filterDateFrom);
    if (filterDateTo) formData.append('date_to', filterDateTo);
    if (filterFolderStatus) formData.append('folder_status', filterFolderStatus);
    if (filterHasDebt) formData.append('has_debt', filterHasDebt);
    if (filterMedicalAlerts) formData.append('has_medical_alerts', filterMedicalAlerts);

    // Perform AJAX request
    fetch('<?= APP_URL ?>/patients/ajax_search.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        // Cache the results
        searchCache.set(cacheKey, {
            data: data,
            timestamp: Date.now()
        });

        // Update UI with results
        updateUIWithResults(data, hasFilters);

        // Remove searching visual feedback
        searchInputEl.classList.remove('searching');
    })
    .catch(error => {
        console.error('AJAX error:', error);
        showToast('error', 'هەڵەیەک ڕوویدا لە پەیوەندی');
        searchInputEl.classList.remove('searching');
        document.getElementById('loadingIndicator').style.display = 'none';
        document.getElementById('patientsPanel').style.opacity = '1';
        document.getElementById('patientsPanel').style.transform = 'scale(1)';
    });
}

// Update UI with search results (extracted for cache reuse)
function updateUIWithResults(data, hasFilters) {
    if (data.success) {
        // Update patients list
        document.getElementById('patientsList').innerHTML = data.html;

        // Update pagination
        document.getElementById('paginationContainer').innerHTML = data.pagination;

        // Update statistics cards
        if (data.statistics) {
            document.getElementById('statTotalPatients').textContent = data.statistics.totalPatients.toLocaleString();
            document.getElementById('statNewThisMonth').textContent = data.statistics.newThisMonth.toLocaleString();
            document.getElementById('statActiveFolders').textContent = data.statistics.activeFolders.toLocaleString();
            document.getElementById('statClosedFolders').textContent = data.statistics.closedFolders.toLocaleString();
            document.getElementById('statActiveCases').textContent = data.statistics.activeCases.toLocaleString();
            document.getElementById('statPatientsWithDebt').textContent = data.statistics.patientsWithDebt.toLocaleString();
            document.getElementById('statTotalDebt').innerHTML = data.statistics.totalDebt.toLocaleString() + ' <small style="font-size: 14px;"></small>';

            // Show stats container when filters are active
            document.getElementById('statsContainer').style.display = hasFilters ? 'grid' : 'none';
        }

        // Attach pagination click handlers
        attachPaginationHandlers();

        currentPage = data.page;

        // Hide loading indicator with smooth transition
        document.getElementById('loadingIndicator').style.display = 'none';
        document.getElementById('patientsPanel').style.opacity = '1';
        document.getElementById('patientsPanel').style.transform = 'scale(1)';

        // Scroll to top of results
        document.getElementById('patientsPanel').scrollIntoView({ behavior: 'smooth', block: 'start' });
    } else {
        console.error('Search error:', data.error);
        showToast('error', 'هەڵەیەک ڕوویدا لە گەڕان');
        document.getElementById('loadingIndicator').style.display = 'none';
        document.getElementById('patientsPanel').style.opacity = '1';
        document.getElementById('patientsPanel').style.transform = 'scale(1)';
    }
}

// Attach pagination handlers
function attachPaginationHandlers() {
    document.querySelectorAll('.pagination-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            if (!this.classList.contains('disabled') && !this.classList.contains('active')) {
                const page = parseInt(this.getAttribute('data-page'));
                performSearch(page);
            }
        });
    });
}

// Search button click
document.getElementById('searchBtn').addEventListener('click', function() {
    performSearch(1);
});

// Search on Enter key
document.getElementById('searchInput').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        performSearch(1);
    }
});

// Minimum characters before the clinic-wide directory hits the server.
// Must match DIRECTORY_SEARCH_MIN_CHARS in config.php — the server enforces it
// as well, this only avoids the pointless round-trips while typing.
const DIRECTORY_MIN_CHARS = <?= (int)DIRECTORY_SEARCH_MIN_CHARS ?>;

// Real-time search, debounced. 200ms is short enough to feel live and long
// enough that a fast typist triggers one request per word, not per keystroke.
document.getElementById('searchInput').addEventListener('input', function() {
    updateActiveFilterCount();
    clearTimeout(searchTimeout);

    const query = this.value.trim();
    // "My patients" is a small owned list, so it may search from 2 characters
    // and reset instantly when cleared. The clinic directory is lazy: it stays
    // on the prompt until DIRECTORY_MIN_CHARS, and clearing returns to it.
    const isDirectory = (currentViewMode === 'all');
    const minChars = isDirectory ? DIRECTORY_MIN_CHARS : 2;

    if (query.length === 0) {
        performSearch(1);
        return;
    }

    searchTimeout = setTimeout(() => {
        if (query.length >= minChars) {
            performSearch(1);
        }
    }, 200);
});

// Filter change triggers search and updates count
['filterGender', 'filterDateFrom', 'filterDateTo', 'filterFolderStatus', 'filterHasDebt', 'filterMedicalAlerts'].forEach(id => {
    document.getElementById(id).addEventListener('change', function() {
        updateActiveFilterCount();
        performSearch(1);
    });
});

// Clear all filters function
function clearAllFilters() {
    document.getElementById('searchInput').value = '';
    document.getElementById('filterGender').value = '';
    document.getElementById('filterDateFrom').value = '';
    document.getElementById('filterDateTo').value = '';
    document.getElementById('filterFolderStatus').value = '';
    document.getElementById('filterHasDebt').value = '';
    document.getElementById('filterMedicalAlerts').value = '';
    updateActiveFilterCount();
    // Clear cache when filters are cleared
    searchCache.clear();
    // Hide statistics container when clearing filters
    document.getElementById('statsContainer').style.display = 'none';
    performSearch(1);
}

// Clear all filters (main button)
document.getElementById('clearBtn').addEventListener('click', function() {
    clearAllFilters();
});

// Clear all filters (quick button in filters panel)
document.getElementById('quickClearFilters').addEventListener('click', function() {
    clearAllFilters();
});

// Stat card click → apply matching filter
function applyStatCardFilter(filter, cardEl) {
    // Reset all filter inputs
    document.getElementById('searchInput').value = '';
    document.getElementById('filterGender').value = '';
    document.getElementById('filterDateFrom').value = '';
    document.getElementById('filterDateTo').value = '';
    document.getElementById('filterFolderStatus').value = '';
    document.getElementById('filterHasDebt').value = '';
    document.getElementById('filterMedicalAlerts').value = '';

    let openPanel = false;
    switch (filter) {
        case 'all':
            // no filters
            break;
        case 'new_month': {
            const now = new Date();
            const pad = n => String(n).padStart(2, '0');
            const firstDay = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-01`;
            const today = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
            document.getElementById('filterDateFrom').value = firstDay;
            document.getElementById('filterDateTo').value = today;
            openPanel = true;
            break;
        }
        case 'active_folders':
        case 'active_cases':
            document.getElementById('filterFolderStatus').value = 'active';
            openPanel = true;
            break;
        case 'closed_folders':
            document.getElementById('filterFolderStatus').value = 'closed';
            openPanel = true;
            break;
        case 'has_debt':
            document.getElementById('filterHasDebt').value = '1';
            openPanel = true;
            break;
    }

    // Visual highlight on selected card
    document.querySelectorAll('.stat-card-patient.active-filter')
        .forEach(c => c.classList.remove('active-filter'));
    if (cardEl && filter !== 'all') cardEl.classList.add('active-filter');

    // Open filter panel so user can see the applied filter
    const panel = document.getElementById('advancedFilters');
    if (panel && openPanel) panel.style.display = 'block';
    if (panel && filter === 'all') panel.style.display = 'none';

    searchCache.clear();
    performSearch(1);
}

document.querySelectorAll('.stat-card-patient[data-filter]').forEach(card => {
    card.addEventListener('click', function() {
        applyStatCardFilter(this.dataset.filter, this);
    });
});

// Initial pagination handlers and filter count
attachPaginationHandlers();
updateActiveFilterCount();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
