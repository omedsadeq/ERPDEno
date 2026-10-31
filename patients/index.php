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
require_once __DIR__ . '/../includes/case-button.php';
require_once __DIR__ . '/../includes/plan-button.php';
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
$hidePageHeader = true;   // the toolbar and the figures strip head the page, as on سەردانەکان

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../classes/Patient.php';
require_once __DIR__ . '/_list.php';

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
// «پلانی چارەسەر» on the rows of patients who have a plan (one query for the page)
$planSummaries = plan_button_summaries($auth, array_column($patients, 'id'));

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

/* ── Toolbar: the search is the page's main tool — big, on a soft panel in
      the accent's colours, with the buttons at the same height ── */
.pt-toolbar {
    background: linear-gradient(135deg, rgba(var(--win-accent-rgb, 0, 120, 212), .11), rgba(34, 195, 230, .07) 55%, #fff);
    border: 1px solid rgba(var(--win-accent-rgb, 0, 120, 212), .2);
    border-radius: 16px;
    padding: 14px 14px 10px;
    margin-bottom: 10px;
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
}
.pt-search {
    position: relative;
    flex: 1 1 320px;
    min-width: 0;
    display: flex;
    align-items: center;
}
/* the magnifier sits in a tile at the start of the box */
.pt-search > i {
    position: absolute;
    inset-inline-start: 9px;
    width: 38px;
    height: 38px;
    border-radius: 11px;
    display: grid;
    place-items: center;
    font-size: 15px;
    color: var(--pt-accent);
    background: rgba(var(--win-accent-rgb, 0, 120, 212), .1);
    pointer-events: none;
    transition: color .15s ease, background .15s ease;
}
.pt-search input {
    width: 100%;
    height: 56px;
    border: 2px solid #d3deea;
    border-radius: 14px;
    padding: 0;
    padding-inline: 58px 46px;
    font-size: 16.5px;
    font-weight: 600;
    font-family: inherit;
    color: var(--pt-ink);
    background: #fff;
    unicode-bidi: plaintext;   /* a typed number keeps its order: 0750 123 4567 */
    box-shadow: 0 2px 8px rgba(15, 23, 42, .05);
    transition: border-color .15s ease, box-shadow .15s ease;
}
.pt-search input::placeholder { color: #94a3b8; font-weight: 500; }
.pt-search input:focus {
    border-color: var(--pt-accent);
    box-shadow: 0 0 0 5px rgba(var(--win-accent-rgb, 0, 120, 212), .14);
    outline: none;
}
.pt-search:focus-within > i { color: #fff; background: var(--pt-accent); }
/* «/» opens the search from anywhere on the page */
.pt-kbd {
    position: absolute;
    inset-inline-end: 12px;
    min-width: 24px;
    height: 24px;
    padding: 0 6px;
    border: 1px solid #d3deea;
    border-bottom-width: 2px;
    border-radius: 6px;
    background: #f8fafc;
    color: #64748b;
    font: 700 12px/20px inherit;
    font-family: inherit;
    text-align: center;
    pointer-events: none;
}
.pt-search:focus-within .pt-kbd { display: none; }
.pt-toolbar > .pt-btn, .pt-toolbar > .pl-new { height: 56px; border-radius: 14px; padding: 0 18px; font-size: 14px; }
.pt-toolbar > .pt-btn i { font-size: 14px; }
.pt-toolbar > .pt-btn--icon { width: 56px; padding: 0; justify-content: center; }
.pt-search-go { font-weight: 800; }
/* the line under the box: what it understands, and how many it found */
.pt-search-meta { flex-basis: 100%; display: flex; flex-wrap: wrap; align-items: center; gap: 4px 14px; padding: 0 4px; font-size: 12px; color: #64748b; }
.pt-hint { display: inline-flex; align-items: center; gap: 5px; }
.pt-hint i { font-size: 11px; color: rgba(var(--win-accent-rgb, 0, 120, 212), .7); }
.pt-found { padding: 2px 10px; border-radius: 999px; background: var(--pt-accent); color: #fff; font-weight: 600; }
.pt-found b { font-weight: 800; }
.pt-found[hidden] { display: none; }
/* the matched part of a name, code or number in the results */
.pl-table mark { background: #fff3a3; color: inherit; border-radius: 3px; padding: 0 1px; }
/* Search in flight: a thin indeterminate bar under the field, no layout shift. */
.pt-search input.searching { border-color: var(--pt-accent); }
.pt-search input.searching + .pt-progress { opacity: 1; }
.pt-progress {
    position: absolute;
    inset-inline: 14px;
    bottom: 2px;
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
    height: 48px;   /* the segment then stands as tall as the search box */
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

/* ── Summary strip: the figures in one line (as on سەردانەکان).
      Each figure still filters the list. ── */
.pl-summary {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 4px 6px;
    padding: 6px 10px;
    margin-bottom: 10px;
    background: var(--pt-bg);
    border: 1px solid var(--pt-line);
    border-radius: var(--pt-r);
}
.pl-stats { display: flex; align-items: center; flex-wrap: wrap; gap: 4px 6px; }
.pl-stat {
    display: inline-flex;
    align-items: baseline;
    gap: 5px;
    padding: 3px 9px;
    border: 1px solid transparent;
    border-radius: 7px;
    background: none;
    font: inherit;
    font-size: 12.5px;
    color: #64748b;
    white-space: nowrap;
    cursor: pointer;
    min-height: 0;   /* the phone layer gives every button a tall tap height */
}
.pl-stat b { font-size: 15px; font-weight: 800; color: var(--pt-ink); font-variant-numeric: tabular-nums; }
.pl-stat:hover { background: var(--pt-tint); }
.pl-stat.is-total { background: #eef6fc; }
.pl-stat.is-total b { color: #005a9e; }
.pl-stat.is-new b { color: #15803d; }
.pl-stat.is-open b { color: #0f766e; }
.pl-stat.is-closed b { color: #64748b; }
.pl-stat.is-case b { color: #7c3aed; }
.pl-stat.is-debt b { color: #b3261e; }
.pl-stat.active-filter { border-color: var(--pt-accent); background: rgba(var(--win-accent-rgb, 0, 120, 212), .06); }
.pl-sep { width: 1px; height: 18px; background: var(--pt-line); }
/* «نەخۆشی نوێ» at the toolbar's end, green as «سەردانی نوێ» */
.pl-new {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    height: 36px;
    padding: 0 14px;
    border-radius: 8px;
    background: #16a34a;
    color: #fff;
    font-size: 13px;
    font-weight: 700;
    text-decoration: none;
    white-space: nowrap;
}
.pl-new:hover { background: #15803d; color: #fff; }

/* ── Panel + loader ── */
.pt-panel {
    background: var(--pt-bg);
    border: 1px solid var(--pt-line);
    border-radius: var(--pt-r);
}
.pt-panel .panel-body { padding: 0; }
#loadingIndicator { text-align: center; padding: 28px; }
#loadingIndicator i { font-size: 26px; color: var(--pt-accent); }
#loadingIndicator p { margin-top: 10px; font-size: 12.5px; font-weight: 600; color: var(--pt-ink-2); }

/* ── The table (patients/_list.php): classic — ruled, striped, sticky head,
      one line a patient ── */
.pl-table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 13px; }
.pl-table th {
    position: sticky;
    top: 0;
    z-index: 2;
    background: #f4f6f9;
    color: #334155;
    font-size: 12px;
    font-weight: 800;
    text-align: start;
    padding: 8px 6px;
    border-bottom: 1px solid #d9e0e8;
    white-space: nowrap;
}
.pl-table th:first-child { border-start-start-radius: var(--pt-r); }
.pl-table th:last-child { border-start-end-radius: var(--pt-r); }
.pl-table td { padding: 6px; border-bottom: 1px solid #eef1f4; vertical-align: middle; line-height: 1.45; }
.pl-table tbody tr:nth-child(even) td { background: #fafbfc; }
.pl-table tbody tr:hover td { background: #f2f8fe; }
.pl-table .c-no {
    width: 1%;
    color: #94a3b8;
    font-size: 12px;
    text-align: center;
    font-variant-numeric: tabular-nums;
    border-inline-start: 3px solid transparent;
}
/* a medical alert or another doctor's patient: a coloured line at the row's start */
.pl-table tr.has-alert .c-no { border-inline-start-color: var(--pt-danger); }
.pl-table tr.is-restricted .c-no { border-inline-start-color: #e8a33d; }
.pl-num { text-align: center !important; font-variant-numeric: tabular-nums; }
.pl-muted { color: #94a3b8; }
.pl-pt { display: flex; align-items: center; gap: 8px; min-width: 170px; }
.pl-avatar {
    flex-shrink: 0;
    width: 28px;
    height: 28px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    font-weight: 800;
    text-decoration: none;
    background: #e8f1fb;
    color: #0b5da8;
}
.pl-avatar.is-female { background: #fdecf3; color: #b0286b; }
.pl-who { min-width: 0; }
.pl-line { display: flex; align-items: center; gap: 5px; flex-wrap: wrap; }
.pl-name { font-weight: 700; color: var(--pt-ink); text-decoration: none; }
.pl-name:hover { color: var(--pt-accent); }
.pl-alert { color: var(--pt-danger); font-size: 12px; }
.pl-sub { display: block; font-size: 11.5px; color: #64748b; text-align: start; }
.pl-xray { color: #00897b; }
.pl-chip { display: inline-block; padding: 0 7px; border-radius: 6px; font-size: 10.5px; font-weight: 700; line-height: 18px; white-space: nowrap; }
.pl-chip.is-male { background: #e8f1fb; color: #0b5da8; }
.pl-chip.is-female { background: #fdecf3; color: #b0286b; }
.pl-chip.is-locked { background: #fff3e0; color: #a35b00; }
.pl-chip.is-locked i { font-size: 9px; }
.pl-table .c-phone a { color: inherit; text-decoration: none; font-variant-numeric: tabular-nums; white-space: nowrap; }
.pl-table .c-phone a:hover { color: var(--pt-accent); }
.pl-table .c-date { white-space: nowrap; color: #475569; font-variant-numeric: tabular-nums; }
.pl-debt { color: #b3261e; font-variant-numeric: tabular-nums; white-space: nowrap; }
.pl-actions { display: flex; align-items: center; gap: 4px; justify-content: flex-end; }
.pl-act {
    width: 30px;
    height: 30px;
    border: 1px solid #e2e8f0;
    border-radius: 7px;
    background: #fff;
    color: #475569;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 12.5px;
    text-decoration: none;
}
.pl-act:hover { background: #f1f5f9; color: var(--pt-ink); }
.pl-act.is-money { color: #0878d1; }
.pl-act.is-danger:hover { background: #fef2f2; color: #dc2626; border-color: #fecaca; }
.pl-actions .case-go-btn { height: 30px; }
.pl-actions .plan-go-btn { height: 30px; font-size: 12px; }

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
    padding: 10px;
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

/* The breakpoints follow the page's own width (container queries), not the
   screen's: an open or closed sidebar changes the room the table really has. */
.pt-scope { container: pl / inline-size; }

/* Narrower desks: no «#», «کەیس» and «پلان» are icon-only */
@container pl (max-width: 1000px) {
    .pl-table .c-no { display: none; }
    .pl-table tr.has-alert .c-patient { box-shadow: inset -3px 0 0 var(--pt-danger); }
    .pl-table tr.is-restricted .c-patient { box-shadow: inset -3px 0 0 #e8a33d; }
    .pl-actions .case-go-btn span, .pl-actions .plan-go-btn span { display: none; }
    .pl-actions .plan-go-btn { width: 30px; padding: 0; justify-content: center; }
}

/* Tablets and phones: each patient is a card, as many side by side as fit */
@container pl (max-width: 820px) {
    .pl-table thead { display: none; }
    .pl-table { display: block; }
    .pl-table tbody { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 8px; padding: 8px; }
    .pl-table tr { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px 12px; padding: 10px 12px; align-content: start;
                   border: 1px solid var(--pt-line); border-inline-start: 3px solid #cbd5e1; border-radius: 9px; background: #fff; }
    .pl-table tr.has-alert { border-inline-start-color: var(--pt-danger); }
    .pl-table tr.is-restricted { border-inline-start-color: #e8a33d; }
    .pl-table td { display: block; padding: 0; border: 0; background: none !important; box-shadow: none !important; min-width: 0; }
    .pl-table td.c-patient, .pl-table td.c-act { grid-column: 1 / -1; }
    .pl-table td[data-label]::before { content: attr(data-label); display: block; color: #94a3b8; font-size: 10.5px; font-weight: 700; margin-bottom: 1px; }
    .pl-table td.is-empty { display: none; }
    .pl-table .pl-num { text-align: start !important; }
    .pl-pt { min-width: 0; }
    .pl-actions { justify-content: flex-start; flex-wrap: wrap; padding-top: 8px; border-top: 1px dashed var(--pt-line); }
    .pt-panel { background: var(--pt-tint); }
}

/* Phones: the toolbar and the figures wrap */
@container pl (max-width: 640px) {
    .pt-toolbar { padding: 10px; }
    .pt-toolbar > .pt-btn, .pt-toolbar > .pl-new { flex: 1 1 auto; justify-content: center; height: 46px; }
    .pt-search { flex-basis: 100%; }
    .pt-search input { height: 52px; font-size: 16px; }
    .pt-kbd, .pt-hint { display: none; }
    .pt-seg { width: 100%; }
    .view-mode-btn { flex: 1; justify-content: center; }
    .pl-sep { display: none; }
    .pl-stat { padding: 2px 6px; }
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
               placeholder="<?= ($isDoctor && !$isAdmin && $viewMode === 'all') ? 'گەڕان لە نەخۆشەکانی تری کلینیک...' : 'ناو، مۆبایل یان کۆدی نەخۆش بنووسە...' ?>"
               value="<?= htmlspecialchars($search) ?>" autocomplete="off" aria-label="گەڕان" aria-describedby="searchMeta">
        <div class="pt-progress" aria-hidden="true"></div>
        <i class="fas fa-search"></i>
        <kbd class="pt-kbd" title="کلیلی / بۆ گەڕان">/</kbd>
    </div>

    <button type="button" class="pt-btn pt-btn--primary pt-search-go" id="searchBtn" title="گەڕان" aria-label="گەڕان">
        <i class="fas fa-search"></i> <span>گەڕان</span>
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
    <a href="<?= APP_URL ?>/patients/add.php" class="pl-new"><i class="fas fa-plus"></i> نەخۆشی نوێ</a>

    <?php // what the box understands, then — while searching — how many were found ?>
    <div class="pt-search-meta" id="searchMeta" aria-live="polite">
        <span class="pt-found" id="searchFound"<?= $search === '' ? ' hidden' : '' ?>><b><?= number_format($totalPatients) ?></b> نەخۆش بۆ «<bdi id="searchFoundQ"><?= htmlspecialchars($search) ?></bdi>»</span>
        <span class="pt-hint"><i class="fas fa-user"></i> ناو — هەر بەشێک، بە هەر ڕیزبەندییەک</span>
        <span class="pt-hint"><i class="fas fa-mobile-alt"></i> مۆبایل — بە بۆشایی، 0 یان <bdi dir="ltr">+964</bdi></span>
        <span class="pt-hint"><i class="fas fa-id-card"></i> کۆد</span>
    </div>
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

<!-- Figures strip: each figure filters the list -->
<div class="pl-summary" id="statsContainer" style="<?= $search ? 'display: none;' : '' ?>">
    <div class="pl-stats">
        <button type="button" class="pl-stat is-total stat-card-patient" data-filter="all" title="هەموو نەخۆشەکان">
            <b id="statTotalPatients"><?= $search ? '0' : number_format($totalPatients) ?></b> نەخۆش
        </button>
        <button type="button" class="pl-stat is-new stat-card-patient" data-filter="new_month" title="نەخۆشی نوێ ئەم مانگە">
            <b id="statNewThisMonth"><?= $search ? '0' : number_format($newPatientsThisMonth) ?></b> نوێی ئەم مانگە
        </button>
        <i class="pl-sep"></i>
        <button type="button" class="pl-stat is-open stat-card-patient" data-filter="active_folders" title="نەخۆشانی دۆسیەی کراوە">
            <b id="statActiveFolders"><?= $search ? '0' : number_format($activeFoldersCount) ?></b> دۆسیەی کراوە
        </button>
        <button type="button" class="pl-stat is-closed stat-card-patient" data-filter="closed_folders" title="نەخۆشانی دۆسیەی داخراو">
            <b id="statClosedFolders"><?= $search ? '0' : number_format($closedFoldersCount) ?></b> دۆسیەی داخراو
        </button>
        <button type="button" class="pl-stat is-case stat-card-patient" data-filter="active_cases" title="نەخۆشانی کەیسی کراوە">
            <b id="statActiveCases"><?= $search ? '0' : number_format($activeCases) ?></b> کەیسی کراوە
        </button>
        <i class="pl-sep"></i>
        <button type="button" class="pl-stat is-debt stat-card-patient" data-filter="has_debt" title="نەخۆشانی بە قەرز">
            <b id="statPatientsWithDebt"><?= $search ? '0' : number_format($patientsWithDebt) ?></b> بە قەرز
        </button>
        <button type="button" class="pl-stat is-debt stat-card-patient" data-filter="has_debt" title="کۆی پارەی قەرز">
            <b id="statTotalDebt"><?= $search ? '0' : number_format($totalDebtAmount) ?></b> کۆی قەرز
        </button>
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
        <div id="patientsList" class="pl-list">
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
            <?= patients_list_table($patients, [
                'restricted' => $isDoctor && !$isAdmin && !$isDoctorPatientFilter && $viewMode === 'all',
                'start'      => ($page - 1) * ITEMS_PER_PAGE + 1,
                'auth'       => $auth,
                'plans'      => $planSummaries,
                'manager'    => $patientManager,
                'search'     => $search,
            ]) ?>
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
               data-page="1" class="pagination-btn <?= $page <= 1 ? 'disabled' : '' ?>" <?= $page <= 1 ? 'disabled' : '' ?>>
                <i class="fas fa-angle-double-right"></i>
            </a>
            <a href="?page=<?= $page - 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $paginationContext ?>"
               data-page="<?= $page - 1 ?>" class="pagination-btn <?= $page <= 1 ? 'disabled' : '' ?>" <?= $page <= 1 ? 'disabled' : '' ?>>
                <i class="fas fa-angle-right"></i>
            </a>

            <?php
            $start = max(1, $page - 2);
            $end = min($totalPages, $page + 2);
            for ($i = $start; $i <= $end; $i++):
            ?>
            <a href="?page=<?= $i ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $paginationContext ?>"
               data-page="<?= $i ?>" class="pagination-btn <?= $i === $page ? 'active' : '' ?>">
                <?= $i ?>
            </a>
            <?php endfor; ?>

            <a href="?page=<?= $page + 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $paginationContext ?>"
               data-page="<?= $page + 1 ?>" class="pagination-btn <?= $page >= $totalPages ? 'disabled' : '' ?>" <?= $page >= $totalPages ? 'disabled' : '' ?>>
                <i class="fas fa-angle-left"></i>
            </a>
            <a href="?page=<?= $totalPages ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $paginationContext ?>"
               data-page="<?= $totalPages ?>" class="pagination-btn <?= $page >= $totalPages ? 'disabled' : '' ?>" <?= $page >= $totalPages ? 'disabled' : '' ?>>
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
// The clinic-wide figures the strip opened with (none when it opened on a search)
const initialStats = <?= $search ? 'null' : 'Object.fromEntries(Array.from(document.querySelectorAll(\'#statsContainer b[id]\')).map(b => [b.id, b.textContent]))' ?>;

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

        // Update statistics cards. With nothing searched or filtered the strip
        // goes back to the clinic-wide figures it opened with (the endpoint's
        // own figures cover only the rows of one page).
        if (!hasFilters && initialStats) {
            Object.keys(initialStats).forEach(id => { document.getElementById(id).textContent = initialStats[id]; });
            document.getElementById('statsContainer').style.display = 'flex';
        } else if (data.statistics) {
            document.getElementById('statTotalPatients').textContent = data.statistics.totalPatients.toLocaleString();
            document.getElementById('statNewThisMonth').textContent = data.statistics.newThisMonth.toLocaleString();
            document.getElementById('statActiveFolders').textContent = data.statistics.activeFolders.toLocaleString();
            document.getElementById('statClosedFolders').textContent = data.statistics.closedFolders.toLocaleString();
            document.getElementById('statActiveCases').textContent = data.statistics.activeCases.toLocaleString();
            document.getElementById('statPatientsWithDebt').textContent = data.statistics.patientsWithDebt.toLocaleString();
            document.getElementById('statTotalDebt').textContent = data.statistics.totalDebt.toLocaleString();

            // Show stats container when filters are active
            document.getElementById('statsContainer').style.display = hasFilters ? 'flex' : 'none';
        }

        // Attach pagination click handlers
        attachPaginationHandlers();

        // «12 نەخۆش بۆ "…"» under the box while a search is typed
        const q = document.getElementById('searchInput').value.trim();
        const found = document.getElementById('searchFound');
        found.hidden = q === '';
        found.querySelector('b').textContent = Number(data.total || 0).toLocaleString();
        document.getElementById('searchFoundQ').textContent = q;

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

// «/» anywhere on the page jumps to the search; Esc in it clears it
document.addEventListener('keydown', function(e) {
    const el = document.activeElement;
    const typing = el && (/^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName) || el.isContentEditable);
    if (e.key === '/' && !typing && !e.ctrlKey && !e.metaKey && !e.altKey) {
        e.preventDefault();
        const box = document.getElementById('searchInput');
        box.focus();
        box.select();
    }
});
document.getElementById('searchInput').addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && this.value !== '') {
        e.preventDefault();
        this.value = '';
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
    // The strip comes back with the opening figures once the results arrive
    if (!initialStats) document.getElementById('statsContainer').style.display = 'none';
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
