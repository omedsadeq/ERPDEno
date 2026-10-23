<?php
/**
 * View Patient Page
 * سیستەمی زیرەکی کلینیکی ددان
 *
 * Doctor Privacy System:
 * - If doctor owns the patient (has folders) → Full access
 * - If doctor doesn't own the patient → Restricted view (basic profile + X-rays only)
 * - Admin/Staff → Full access to all patients
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Patient.php';
require_once __DIR__ . '/../classes/Folder.php';
require_once __DIR__ . '/../classes/CaseManager.php';
require_once __DIR__ . '/../classes/Appointment.php';
require_once __DIR__ . '/../classes/XrayManager.php';

$auth = new Auth();
$auth->requireLogin();

$patientId = intval($_GET['id'] ?? 0);
if (!$patientId) {
    header('Location: ' . APP_URL . '/patients/index.php');
    exit;
}

$patientManager = new Patient();
$folderManager = new Folder();
$appointmentManager = new Appointment();
$currentUser = $auth->getCurrentUser();
$isAdmin = $auth->isAdmin();
$isDoctor = $auth->isDoctor();
$isReceptionist = $auth->isReceptionist();
$hasFullAccess = $isAdmin || $isReceptionist;
$doctorId = $currentUser['id'];
$db = Database::getInstance();
$doctorOptions = $auth->getDoctors();
$workTypes = $db->fetchAll("SELECT id, work_name FROM work_types WHERE is_active = 1 ORDER BY sort_order, id");
$appointmentCreateError = '';
$defaultAppointmentDate = date('Y-m-d');

// Check if doctor owns this patient (has folders assigned)
// Multi-Doctor Support: A patient can have folders from multiple doctors
// Each doctor only sees their own folders for this patient
$isOwner = true; // Default for admin/staff
$isRestrictedView = false;
$hasOtherDoctorFolders = false; // Patient has folders from OTHER doctors
$accessLevel = 'full'; // full | profile_xray | xray_only | denied
$canViewProfile = true;
$canViewXray = true;

if ($isDoctor && !$isAdmin) {
    $isOwner = $patientManager->isPatientOwnedByDoctor($patientId, $doctorId);
    // Check if patient has folders from other doctors (not current doctor)
    $hasOtherDoctorFolders = $patientManager->patientHasOtherDoctorFolders($patientId, $doctorId);

    // Get access level based on admin permissions
    $accessLevel = $patientManager->getDoctorAccessLevel($patientId, $doctorId);

    // Check permissions
    if ($accessLevel === 'denied') {
        // No access - redirect back
        header('Location: ' . APP_URL . '/patients/index.php?error=no_permission');
        exit;
    }

    $canViewProfile = in_array($accessLevel, ['full', 'profile_xray', 'profile_only']);
    $canViewXray = in_array($accessLevel, ['full', 'profile_xray', 'xray_only']);

    // Restricted view only if doctor doesn't own the patient
    $isRestrictedView = !$isOwner;
}

// Get patient data - Full profile for everyone (doctors need to see basic info to create folders)
$patient = $patientManager->getById($patientId);
if (!$patient) {
    header('Location: ' . APP_URL . '/patients/index.php');
    exit;
}

// Get doctor's own folders (even if they have none yet)
if ($isDoctor && !$isAdmin) {
    // Doctor sees only their own folders for this patient
    $folders = $patientManager->getFoldersForDoctor($patientId, $doctorId);
    $statistics = $patientManager->getStatisticsForDoctor($patientId, $doctorId);
    $appointments = $appointmentManager->getByPatientAndDoctor($patientId, $doctorId);
} else {
    // Admin/Staff sees all folders
    $folders = $patientManager->getFolders($patientId);
    $statistics = $patientManager->getStatistics($patientId);
    $appointments = $appointmentManager->getByPatient($patientId);
}

// Auto-Folder UI: the page lists the patient's CASES directly.
// Folders remain only as a hidden technical layer for financial aggregation.
$casesScopeSql = ($isDoctor && !$isAdmin) ? " AND pf.doctor_id = :doctor_id" : "";
$casesParams = ['patient_id' => $patientId];
if ($isDoctor && !$isAdmin) {
    $casesParams['doctor_id'] = $doctorId;
}
$patientCases = $db->fetchAll(
    "SELECT c.*, pf.doctor_id AS case_doctor_id, u.full_name AS doctor_name
     FROM cases c
     JOIN patient_folders pf ON c.folder_id = pf.id
     LEFT JOIN users u ON u.id = pf.doctor_id
     WHERE pf.patient_id = :patient_id{$casesScopeSql}
     ORDER BY c.created_at DESC, c.id DESC",
    $casesParams
);

// Get data that's visible to all (medical conditions for safety)
$medicalConditions = $patientManager->getMedicalConditions($patientId);
$familyMembers = $isRestrictedView ? [] : $patientManager->getFamilyMembers($patientId);

// Get X-ray data (visible to all doctors for patient care)
$xrayManager = new XrayManager();
$xrayDebt = $xrayManager->getPatientXrayDebt($patientId);
$xrayPaid = $xrayManager->getPatientXrayPaid($patientId);
$patientXrays = $xrayManager->getByPatient($patientId);
$xrayCount = count($patientXrays);

// Combined totals: folder debt + X-ray debt (only for own data)
$statistics['xray_debt'] = $xrayDebt;
$statistics['xray_paid'] = $xrayPaid;
$statistics['total_debt'] = ($statistics['total_debt'] ?? 0) + $xrayDebt;
$statistics['total_paid'] = ($statistics['total_paid'] ?? 0) + $xrayPaid;

// Log access for audit
$accessType = $isOwner ? 'view_profile_full' : 'view_profile_new_patient';
$patientManager->logAccess($patientId, $doctorId, $accessType);

// Flash error + form-restore left by a failed POST (see patient_view_fail)
$flashError = $_SESSION['patient_flash_error'] ?? null;
unset($_SESSION['patient_flash_error']);
$formRestore = $_SESSION['patient_form_restore'] ?? null;
unset($_SESSION['patient_form_restore']);

// Whitelist the initial tab (comes from ?tab= — must never reach the inline
// <script> unescaped, and must be one of the real tabs)
$validTabs = ['folders', 'appointments', 'family', 'notes', 'xray'];
$initialTab = isset($_GET['tab']) && in_array($_GET['tab'], $validTabs, true)
    ? $_GET['tab']
    : (($appointmentCreateError !== '' || ($formRestore['dialog'] ?? '') === 'newAppointmentDialog') ? 'appointments' : 'folders');

// Next upcoming appointment (for the profile card)
$nextAppointmentRow = null;
foreach ($appointments as $apt) {
    if ($apt['appointment_date'] >= date('Y-m-d')
        && !in_array($apt['status'], ['completed', 'cancelled', 'no_show'], true)) {
        if ($nextAppointmentRow === null
            || $apt['appointment_date'] < $nextAppointmentRow['appointment_date']
            || ($apt['appointment_date'] === $nextAppointmentRow['appointment_date']
                && $apt['appointment_time'] < $nextAppointmentRow['appointment_time'])) {
            $nextAppointmentRow = $apt;
        }
    }
}

global $MEDICAL_CONDITIONS;

$pageTitle = $patient['full_name'];
$breadcrumb = [
    ['title' => 'نەخۆشەکان', 'url' => APP_URL . '/patients/index.php'],
    ['title' => $patient['full_name']]
];

/**
 * PRG for failures: stash the error (+ the submitted values so the dialog can
 * reopen pre-filled) in the session, then redirect. Without this a failed
 * create re-rendered the page with the POST still live, so a refresh
 * re-submitted it — creating a duplicate case/appointment.
 */
function patient_view_fail($patientId, $message, $dialog = null, $tab = null) {
    $_SESSION['patient_flash_error'] = $message !== '' ? $message : 'هەڵەیەک ڕوویدا، تکایە دووبارە هەوڵبدەرەوە';
    if ($dialog) {
        $values = [];
        foreach ($_POST as $k => $v) {
            if (is_string($v) && $k !== 'csrf_token' && $k !== 'action') {
                $values[$k] = $v;
            }
        }
        $_SESSION['patient_form_restore'] = ['dialog' => $dialog, 'values' => $values];
    }
    $suffix = $tab ? '&tab=' . urlencode($tab) : '';
    header('Location: ' . APP_URL . '/patients/view.php?id=' . $patientId . $suffix);
    exit;
}

// Handle new folder creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    Csrf::require(); // covers create_case, create_folder, create_appointment

    // Doctor isolation: a doctor may only create a case/folder/appointment for a
    // patient they already own or that reception referred to them. This prevents
    // a doctor from self-assigning an arbitrary patient (which would make them the
    // owner and grant full access) by POSTing a forged patient id.
    if (AccessGuard::isRestrictedDoctor()) {
        AccessGuard::requirePatientGeneral($patientId, false);
    }

    if ($_POST['action'] === 'create_case') {
        // Auto-Folder flow: the UI creates cases directly on the patient;
        // the folder level is resolved silently behind the scenes.

        $caseDoctorId = $hasFullAccess ? intval($_POST['doctor_id'] ?? 0) : intval($currentUser['id']);
        if ($caseDoctorId <= 0) {
            $caseDoctorId = intval($currentUser['id']);
        }

        $autoFolderId = $folderManager->findOrCreateDefault($patientId, $caseDoctorId);
        if ($autoFolderId) {
            $caseManager = new CaseManager();
            $result = $caseManager->create([
                'folder_id'       => $autoFolderId,
                'case_name'       => trim($_POST['case_name'] ?? ''),
                'scope_type'      => in_array($_POST['scope_type'] ?? '', ['tooth', 'arch'], true) ? $_POST['scope_type'] : 'tooth',
                'selected_arch'   => !empty($_POST['selected_arch']) ? $_POST['selected_arch'] : null,
                'chief_complaint' => trim($_POST['chief_complaint'] ?? ''),
                'notes'           => trim($_POST['notes'] ?? '')
            ]);
            if (!empty($result['success'])) {
                AuditLog::log('create_case', 'cases', $result['case_id'], null, [
                    'patient_id' => $patientId,
                    'doctor_id'  => $caseDoctorId,
                    'folder_id'  => $autoFolderId,
                    'source'     => 'patient_direct',
                ]);
                header('Location: ' . APP_URL . '/cases/view.php?id=' . $result['case_id']);
                exit;
            }
            patient_view_fail($patientId, 'هەڵە لە دروستکردنی کەیس' . (!empty($result['message']) ? ': ' . $result['message'] : ''), 'newCaseDialog');
        } else {
            patient_view_fail($patientId, 'هەڵە لە دروستکردنی کەیس (دۆسیەی ناوخۆیی)', 'newCaseDialog');
        }
    } elseif ($_POST['action'] === 'create_folder') {
        $folderData = [
            'patient_id' => $patientId,
            'doctor_id' => $_POST['doctor_id'] ?? $currentUser['id'],
            'folder_name' => trim($_POST['folder_name']),
            'folder_type' => $_POST['folder_type'] ?? 'general',
            'notes' => trim($_POST['notes'] ?? '')
        ];
        $result = $folderManager->create($folderData);
        if ($result['success']) {
            header('Location: ' . APP_URL . '/patients/view.php?id=' . $patientId . '&folder_created=1');
            exit;
        }
        patient_view_fail($patientId, $result['message'] ?? 'هەڵە لە دروستکردنی دۆسیە');
    } elseif ($_POST['action'] === 'create_appointment') {
        $selectedDoctorId = $hasFullAccess
            ? intval($_POST['doctor_id'] ?? 0)
            : intval($currentUser['id']);

        if ($selectedDoctorId <= 0) {
            $selectedDoctorId = intval($currentUser['id']);
        }

        $apptDate = trim($_POST['appointment_date'] ?? '');
        $apptTime = trim($_POST['appointment_time'] ?? '');
        // Validate date/time before hitting the manager, and block past dates —
        // an appointment can be today or later, never yesterday.
        $dateObj = DateTime::createFromFormat('Y-m-d', $apptDate);
        if (!$dateObj || $dateObj->format('Y-m-d') !== $apptDate) {
            patient_view_fail($patientId, 'بەرواری مەوعید نادروستە', 'newAppointmentDialog', 'appointments');
        }
        if ($apptDate < date('Y-m-d')) {
            patient_view_fail($patientId, 'ناتوانرێت مەوعید بۆ ڕۆژێکی ڕابردوو دابنرێت', 'newAppointmentDialog', 'appointments');
        }
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $apptTime)) {
            patient_view_fail($patientId, 'تکایە کاتێک هەڵبژێرە', 'newAppointmentDialog', 'appointments');
        }

        $appointmentData = [
            'patient_id' => $patientId,
            'doctor_id' => $selectedDoctorId,
            'appointment_date' => $apptDate,
            'appointment_time' => $apptTime,
            'duration' => intval($_POST['duration'] ?? 30) ?: 30,
            'type' => $_POST['type'] ?? 'general',
            'work_type_id' => !empty($_POST['work_type_id']) ? intval($_POST['work_type_id']) : null,
            'promised_amount' => (isset($_POST['promised_amount']) && $_POST['promised_amount'] !== '')
                ? floatval($_POST['promised_amount']) : null,
            'notes' => trim($_POST['notes'] ?? ''),
            'status' => 'scheduled'
        ];

        $result = $appointmentManager->create($appointmentData);
        if ($result['success']) {
            header('Location: ' . APP_URL . '/patients/view.php?id=' . $patientId . '&tab=appointments&appointment_created=1');
            exit;
        }

        patient_view_fail($patientId, $result['message'] ?? 'هەڵە لە دروستکردنی مەوعید', 'newAppointmentDialog', 'appointments');
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* Modern Patient View Styling */

/* Success Alert */
.alert-new-patient {
    background: linear-gradient(135deg, #4caf50, #66bb6a);
    border: none;
    border-radius: 12px;
    padding: 14px 18px;
    margin-bottom: 20px;
    box-shadow: 0 4px 12px rgba(76, 175, 80, 0.25);
    animation: slideDown 0.4s ease;
}

/* Medical Alert Banner - Compact */
.medical-alert-banner {
    background: linear-gradient(135deg, #ff5252, #f44336);
    border-radius: 10px;
    padding: 12px 16px;
    margin-bottom: 16px;
    box-shadow: 0 3px 12px rgba(244, 67, 54, 0.3);
    animation: pulse 2s infinite;
}
@keyframes pulse {
    0%, 100% { box-shadow: 0 3px 12px rgba(244, 67, 54, 0.3); }
    50% { box-shadow: 0 3px 18px rgba(244, 67, 54, 0.5); }
}
.medical-alert-header {
    display: flex;
    align-items: center;
    gap: 6px;
    color: white;
    font-size: 12px;
    font-weight: 600;
    margin-bottom: 8px;
}
.medical-alert-header i {
    font-size: 14px;
    animation: shake 0.5s infinite;
}
@keyframes shake {
    0%, 100% { transform: translateX(0); }
    25% { transform: translateX(-2px); }
    75% { transform: translateX(2px); }
}
.medical-conditions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 6px;
}
.medical-condition-card {
    background: rgba(255, 255, 255, 0.15);
    backdrop-filter: blur(10px);
    border: 2px solid rgba(255, 255, 255, 0.3);
    border-radius: 6px;
    padding: 8px 10px;
    color: white;
}
.medical-condition-card strong {
    display: block;
    font-size: 12px;
    margin-bottom: 2px;
}
.medical-condition-card .condition-details {
    font-size: 10px;
    opacity: 0.95;
    margin-bottom: 4px;
}
.medical-condition-card .severity-badge {
    display: inline-flex;
    align-items: center;
    gap: 2px;
    padding: 2px 6px;
    border-radius: 8px;
    font-size: 9px;
    font-weight: 600;
    background: rgba(255, 255, 255, 0.25);
}

/* Patient Profile Card - Modern & Compact */
.patient-profile-card {
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
    border: 1px solid #e8e8e8;
}
.patient-profile-header {
    background: linear-gradient(135deg, #0078d4, #0063b1);
    padding: 18px 16px;
    text-align: center;
    position: relative;
}
.patient-profile-header::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 30px;
    background: white;
    border-radius: 50% 50% 0 0;
}
.patient-avatar-large {
    width: 70px;
    height: 70px;
    border-radius: 50%;
    background: white;
    color: var(--primary-color);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    font-weight: 800;
    margin: 0 auto 10px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
    border: 3px solid rgba(255, 255, 255, 0.3);
    position: relative;
    z-index: 1;
}
.patient-avatar-large.female {
    background: linear-gradient(135deg, #e91e63, #c2185b);
    color: white;
}
.patient-name-title {
    font-size: 17px;
    font-weight: 700;
    color: white;
    margin: 0 0 5px;
    position: relative;
    z-index: 1;
}
.patient-code-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 12px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 16px;
    color: white;
    font-size: 12px;
    font-weight: 600;
    position: relative;
    z-index: 1;
}
.patient-quick-actions {
    display: flex;
    gap: 8px;
    padding: 12px 14px;
    background: linear-gradient(to bottom, #fafafa, white);
    border-bottom: 1px solid #e8e8e8;
}
.patient-info-table {
    padding: 14px 16px;
}
.info-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 7px 0;
    border-bottom: 1px solid #f5f5f5;
}
.info-row:last-child {
    border-bottom: none;
}
.info-label {
    color: #757575;
    font-size: 12px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 6px;
}
.info-label i {
    color: var(--primary-color);
    font-size: 13px;
    width: 16px;
    text-align: center;
}
.info-value {
    color: #2c3e50;
    font-size: 13px;
    font-weight: 600;
}

/* Statistics Cards - Compact */
.stats-grid-modern {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 8px;
    padding: 12px;
}
.stat-card-modern {
    background: linear-gradient(135deg, var(--bg-color), var(--hover-color));
    border-radius: 8px;
    padding: 10px;
    text-align: center;
    border: 2px solid var(--border-color);
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}
.stat-card-modern::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: var(--accent-color);
}
.stat-card-modern:hover {
    transform: translateY(-3px);
    box-shadow: 0 6px 16px rgba(0, 0, 0, 0.12);
    border-color: var(--accent-color);
}
.stat-card-modern.stat-visits {
    --bg-color: #e3f2fd;
    --hover-color: #bbdefb;
    --border-color: #90caf9;
    --accent-color: #2196f3;
}
.stat-card-modern.stat-folders {
    --bg-color: #e8f5e9;
    --hover-color: #c8e6c9;
    --border-color: #a5d6a7;
    --accent-color: #4caf50;
}
.stat-card-modern.stat-paid {
    --bg-color: #fff3e0;
    --hover-color: #ffe0b2;
    --border-color: #ffcc80;
    --accent-color: #ff9800;
}
.stat-card-modern.stat-debt {
    --bg-color: #ffebee;
    --hover-color: #ffcdd2;
    --border-color: #ef9a9a;
    --accent-color: #f44336;
}
.stat-icon-modern {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    background: var(--accent-color);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    margin: 0 auto 6px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.12);
}
.stat-value-modern {
    font-size: 24px;
    font-weight: 800;
    color: var(--accent-color);
    line-height: 1.2;
    margin-bottom: 2px;
}
.stat-label-modern {
    font-size: 11px;
    color: #546e7a;
    font-weight: 600;
}

/* Tabs - Enhanced */
.tabs-container {
    display: flex;
    gap: 4px;
    background: #f5f5f5;
    padding: 6px;
    border-radius: 10px 10px 0 0;
    border-bottom: 2px solid #e0e0e0;
}
.tab {
    flex: 1;
    padding: 12px 16px;
    background: transparent;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    color: #546e7a;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}
.tab:hover {
    background: rgba(255, 255, 255, 0.6);
    color: var(--primary-color);
}
.tab.active {
    background: white;
    color: var(--primary-color);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}
.tab i {
    font-size: 15px;
}

/* Folder Cards - Enhanced */
.folder-card {
    background: white;
    border: 2px solid #e8e8e8;
    border-radius: 12px;
    padding: 18px;
    cursor: pointer;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}
.folder-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 4px;
    height: 100%;
    background: var(--primary-color);
    transform: scaleY(0);
    transition: transform 0.3s ease;
}
.folder-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);
    border-color: var(--primary-color);
}
.folder-card:hover::before {
    transform: scaleY(1);
}
.folder-card.status-closed {
    opacity: 0.75;
    border-color: #bdbdbd;
}
.folder-card.status-closed::before {
    background: #9e9e9e;
}
.folder-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 14px;
}
.folder-icon-box {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    background: linear-gradient(135deg, #e3f2fd, #bbdefb);
    color: #1976d2;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    box-shadow: 0 2px 8px rgba(33, 150, 243, 0.2);
}
.folder-info {
    flex: 1;
}
.folder-title {
    font-size: 15px;
    font-weight: 700;
    color: #2c3e50;
    margin: 0 0 4px;
}
.folder-doctor {
    font-size: 12px;
    color: #757575;
    display: flex;
    align-items: center;
    gap: 4px;
}
.folder-meta {
    display: flex;
    justify-content: space-between;
    font-size: 12px;
    color: #757575;
    margin-bottom: 12px;
}
.folder-progress {
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px solid #f5f5f5;
}
.progress-info {
    display: flex;
    justify-content: space-between;
    font-size: 12px;
    margin-bottom: 6px;
}
.progress-label {
    color: #757575;
}
.progress-amount {
    font-weight: 700;
    color: #f44336;
}
.progress-bar-container {
    background: #f5f5f5;
    border-radius: 10px;
    height: 8px;
    overflow: hidden;
}
.progress-bar-fill {
    height: 100%;
    background: linear-gradient(90deg, #4caf50, #66bb6a);
    border-radius: 10px;
    transition: width 0.6s ease;
}

/* Empty State - Enhanced */
.empty-state-modern {
    text-align: center;
    padding: 60px 20px;
}
.empty-state-icon-modern {
    width: 80px;
    height: 80px;
    margin: 0 auto 20px;
    border-radius: 50%;
    background: linear-gradient(135deg, #e3f2fd, #bbdefb);
    color: #1976d2;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 36px;
    box-shadow: 0 4px 16px rgba(33, 150, 243, 0.2);
}
.empty-state-title-modern {
    font-size: 18px;
    font-weight: 700;
    color: #2c3e50;
    margin: 0 0 8px;
}
.empty-state-text-modern {
    font-size: 14px;
    color: #757575;
    margin: 0 0 20px;
    max-width: 400px;
    margin-left: auto;
    margin-right: auto;
}

/* Panel Enhancements */
.panel {
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    border: 1px solid #e8e8e8;
    overflow: hidden;
}

/* Table Enhancements */
.table-container {
    border-radius: 8px;
    overflow: hidden;
}
.table thead th {
    background: linear-gradient(135deg, #f5f5f5, #eeeeee);
    font-weight: 700;
    color: #2c3e50;
    border-bottom: 2px solid #e0e0e0;
}
.table tbody tr {
    transition: all 0.2s ease;
}
.table tbody tr:hover {
    background: #f9f9f9;
    transform: scale(1.01);
}

/* Action Button in Header */
.d-flex.justify-between.align-center.mb-3 .btn {
    padding: 10px 20px;
    font-size: 14px;
    border-radius: 10px;
    font-weight: 600;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    transition: all 0.2s ease;
}
.d-flex.justify-between.align-center.mb-3 .btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

/* Notes Section */
.alert.alert-info {
    background: linear-gradient(135deg, #e3f2fd, #bbdefb);
    border: 2px solid #90caf9;
    border-radius: 10px;
    padding: 14px 18px;
    font-size: 14px;
    line-height: 1.6;
}

/* Badge Improvements */
.badge {
    padding: 5px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}
.badge-green {
    background: linear-gradient(135deg, #c8e6c9, #a5d6a7);
    color: #2e7d32;
    border: 1px solid #81c784;
}
.badge-secondary {
    background: linear-gradient(135deg, #e0e0e0, #bdbdbd);
    color: #616161;
    border: 1px solid #9e9e9e;
}

/* X-Ray Cards Grid - Compact */
.xray-cards-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 10px;
}

.xray-card {
    background: white;
    border: 1.5px solid #e8e8e8;
    border-right: 3px solid #4caf50;
    border-radius: 10px;
    padding: 12px;
    cursor: pointer;
    transition: all 0.2s ease;
    position: relative;
}

.xray-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    border-color: var(--primary-color);
}

.xray-card-header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 8px;
}

.xray-card-icon {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    flex-shrink: 0;
}

.xray-card-info {
    flex: 1;
    min-width: 0;
}

.xray-card-number {
    font-size: 12px;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 1px;
    direction: ltr;
    text-align: right;
}

.xray-card-type {
    font-size: 11px;
    color: #757575;
    font-weight: 600;
}

.xray-card-status {
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 10px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 4px;
    border: 1px solid;
    flex-shrink: 0;
}

.xray-card-body {
    padding-top: 8px;
    border-top: 1px solid #f0f0f0;
}

.xray-card-meta {
    display: flex;
    justify-content: space-between;
    font-size: 11px;
    color: #757575;
}

.xray-card-meta span {
    display: flex;
    align-items: center;
    gap: 4px;
}

.xray-card-meta i {
    color: #9e9e9e;
    font-size: 10px;
}

.xray-card-payment {
    margin-top: 6px;
}

.xray-payment-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 8px;
    border-radius: 6px;
    font-size: 10px;
    font-weight: 600;
}

.xray-card-action {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #bdbdbd;
    font-size: 12px;
    transition: all 0.2s ease;
}

.xray-card:hover .xray-card-action {
    left: 8px;
    color: var(--primary-color);
}

/* Appointments Cards Grid - Compact */
.apt-section-header {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    font-weight: 700;
    color: #0078d4;
    margin-bottom: 10px;
    padding-bottom: 8px;
    border-bottom: 2px solid #e3f2fd;
}

.apt-section-header.apt-section-past {
    color: #757575;
    border-bottom-color: #f5f5f5;
    margin-top: 16px;
}

.apt-section-count {
    background: #e3f2fd;
    color: #0078d4;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 700;
}

.apt-section-past .apt-section-count {
    background: #f5f5f5;
    color: #757575;
}

.appointments-cards-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 10px;
}

.apt-card {
    background: white;
    border: 1.5px solid #e8e8e8;
    border-right: 3px solid #2196f3;
    border-radius: 10px;
    padding: 10px 12px;
    position: relative;
    transition: all 0.2s ease;
}

.apt-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
}

.apt-card-today {
    background: linear-gradient(135deg, #fff8e1, #fffde7);
    border-color: #ffc107;
}

.apt-card-past {
    opacity: 0.8;
}

.apt-card-ribbon {
    position: absolute;
    top: 8px;
    left: 8px;
    background: linear-gradient(135deg, #ff9800, #f57c00);
    color: white;
    padding: 2px 8px;
    border-radius: 8px;
    font-size: 10px;
    font-weight: 700;
    box-shadow: 0 2px 4px rgba(255, 152, 0, 0.3);
}

.apt-ribbon-tomorrow {
    background: linear-gradient(135deg, #2196f3, #1976d2);
    box-shadow: 0 2px 4px rgba(33, 150, 243, 0.3);
}

.apt-card-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 8px;
}

.apt-card-datetime {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.apt-card-date, .apt-card-time {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 11px;
    color: #2c3e50;
    font-weight: 600;
}

.apt-card-date i, .apt-card-time i {
    color: #757575;
    font-size: 10px;
    width: 12px;
}

.apt-card-status {
    padding: 3px 8px;
    border-radius: 12px;
    font-size: 10px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 3px;
    border: 1px solid;
}

.apt-card-body {
    padding-top: 8px;
    border-top: 1px solid #f0f0f0;
}

.apt-card-info-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 4px 0;
}

.apt-card-label {
    font-size: 11px;
    color: #757575;
    display: flex;
    align-items: center;
    gap: 4px;
}

.apt-card-label i {
    color: #9e9e9e;
    width: 12px;
    font-size: 10px;
}

.apt-card-value {
    font-size: 11px;
    font-weight: 600;
    color: #2c3e50;
}

.apt-card-notes {
    margin-top: 6px;
    padding: 6px 8px;
    background: #f8f9fa;
    border-radius: 6px;
    font-size: 10px;
    color: #546e7a;
    display: flex;
    align-items: flex-start;
    gap: 4px;
}

.apt-card-notes i {
    color: #ff9800;
    margin-top: 1px;
    font-size: 10px;
}

.apt-card-actions {
    display: flex;
    gap: 6px;
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px solid #f0f0f0;
}

.apt-action-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    padding: 5px 8px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
    border: none;
}

.apt-action-confirm {
    background: #e8f5e9;
    color: #2e7d32;
}

.apt-action-confirm:hover {
    background: #c8e6c9;
}

.apt-action-checkin {
    background: #e3f2fd;
    color: #1565c0;
}

.apt-action-checkin:hover {
    background: #bbdefb;
}

.apt-action-call {
    background: #f3e5f5;
    color: #7b1fa2;
}

.apt-action-call:hover {
    background: #e1bee7;
}

.apt-action-cancel {
    background: #ffebee;
    color: #c62828;
}

.apt-action-cancel:hover {
    background: #ffcdd2;
}

.apt-action-reschedule {
    background: #e3f2fd;
    color: #1565c0;
    flex: 1;
    justify-content: center;
}

.apt-action-reschedule:hover {
    background: #bbdefb;
}

.apt-show-more {
    text-align: center;
    margin-top: 12px;
}

.apt-show-more .btn {
    font-size: 11px;
    padding: 6px 12px;
}

/* Responsive Grid */
@media (max-width: 1200px) {
    .patient-view-grid {
        grid-template-columns: 300px 1fr !important;
    }
}
@media (max-width: 768px) {
    .patient-view-grid {
        grid-template-columns: 1fr !important;
    }
    .stats-grid-modern {
        grid-template-columns: 1fr 1fr;
    }
    .folder-card {
        padding: 14px;
    }
    .xray-cards-grid {
        grid-template-columns: 1fr;
    }
    .appointments-cards-grid {
        grid-template-columns: 1fr;
    }
    .apt-card-actions {
        flex-wrap: wrap;
    }
}
.apt-dialog-modern {
    max-width: 580px;
    width: 96%;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    background: #fff;
    max-height: 92vh;
    max-height: 92dvh;
    display: flex;
    flex-direction: column;
}

.apt-dialog-header {
    background: linear-gradient(135deg, #0078d4 0%, #0063b1 100%);
    color: white;
    padding: 12px 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-shrink: 0;
}

.apt-dialog-header h4 {
    margin: 0;
    font-size: 15px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}

.apt-dialog-close {
    background: rgba(255,255,255,0.15);
    border: none;
    color: white;
    width: 28px;
    height: 28px;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
}

.apt-dialog-close:hover {
    background: rgba(255,255,255,0.25);
}

/* Pass flex layout through a wrapping <form> so the body scrolls and the footer stays visible */
.apt-dialog-modern > form {
    display: flex;
    flex-direction: column;
    flex: 1;
    min-height: 0;
}

.apt-dialog-body {
    padding: 14px 16px;
    overflow-y: auto;
    flex: 1;
    min-height: 0;
}

.apt-form-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 10px;
}

.apt-form-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.apt-form-field.apt-field-full {
    grid-column: 1 / -1;
}

.apt-form-field.apt-field-2col {
    grid-column: span 2;
}

.apt-form-field label {
    font-size: 11px;
    font-weight: 600;
    color: #546e7a;
    display: flex;
    align-items: center;
    gap: 5px;
}

.apt-form-field label i {
    color: #0078d4;
    font-size: 10px;
}

.apt-form-field label .required {
    color: #f44336;
    font-size: 10px;
}

.apt-form-field input,
.apt-form-field select,
.apt-form-field textarea {
    padding: 8px 10px;
    border: 1.5px solid #e0e0e0;
    border-radius: 6px;
    font-size: 12px;
    font-family: inherit;
    transition: all 0.2s;
    background: #fafafa;
}

.apt-form-field input:focus,
.apt-form-field select:focus,
.apt-form-field textarea:focus {
    border-color: #0078d4;
    box-shadow: 0 0 0 2px rgba(0, 120, 212, 0.1);
    outline: none;
    background: #fff;
}

.apt-selected-patient {
    display: flex;
    padding: 6px 10px;
    background: linear-gradient(135deg, #e8f4fd, #f5faff);
    border: 1.5px solid #0078d4;
    border-radius: 6px;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    min-height: 48px;
}

.apt-time-section > label {
    font-size: 11px;
    font-weight: 600;
    color: #546e7a;
    display: flex;
    align-items: center;
    gap: 5px;
    margin-bottom: 6px;
}

.apt-time-section > label i {
    color: #0078d4;
    font-size: 10px;
}

.apt-time-section > label .required {
    color: #f44336;
    font-size: 10px;
}

.apt-time-grid-container {
    background: #f8f9fa;
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    padding: 8px;
    max-height: 120px;
    overflow-y: auto;
}

.smart-time-picker-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(86px, 1fr));
    gap: 8px;
}

.smart-time-slot {
    padding: 8px 4px;
    border: 1.5px solid #e0e0e0;
    border-radius: 6px;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s;
    font-size: 12px;
    font-weight: 600;
    background: white;
    color: #37474f;
}

.smart-time-slot:hover:not(.busy):not(.break) {
    border-color: #0078d4;
    background: #e3f2fd;
    color: #0078d4;
    transform: translateY(-1px);
}

.smart-time-slot.selected {
    background: #0078d4;
    color: white;
    border-color: #0078d4;
}

.smart-time-slot.busy {
    background: #ffebee;
    border-color: #ffcdd2;
    color: #c62828;
    cursor: not-allowed;
    opacity: 0.7;
}

/* Booked slots stay selectable (overlapping bookings allowed) */
.smart-time-slot.booked {
    background: #ffebee;
    border-color: #ffcdd2;
    color: #c62828;
    cursor: pointer;
}

.smart-time-slot.booked.selected {
    background: #0078d4;
    color: white;
    border-color: #0078d4;
}

.smart-time-slot.break {
    background: #fff3e0;
    border-color: #ffe0b2;
    color: #e65100;
    cursor: not-allowed;
    opacity: 0.7;
}

.existing-bookings-section {
    margin: 10px 0;
    padding: 8px 10px;
    background: linear-gradient(135deg, #fff8e1, #fffde7);
    border-radius: 6px;
    border: 1px solid #ffe082;
}

.existing-bookings-section label {
    display: block;
    font-weight: 600;
    font-size: 10px;
    color: #ef6c00;
    margin-bottom: 6px;
}

.existing-bookings-list {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.existing-booking-item {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 10px;
    background: white;
    border: 1px solid #ffcc80;
    border-radius: 16px;
    font-size: 11px;
    color: #e65100;
}

.existing-booking-item i {
    font-size: 9px;
}

.existing-booking-item .patient-name {
    font-weight: 600;
    color: #bf360c;
    max-width: 80px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.no-bookings-message {
    padding: 8px;
    text-align: center;
    color: #7cb342;
    font-size: 12px;
}

.booking-summary-apt {
    margin-top: 10px;
    padding: 8px 12px;
    background: linear-gradient(135deg, #e8f4fd 0%, #d4e9fc 100%);
    border-radius: 6px;
    border: 1px solid #90caf9;
}

.booking-summary-apt .summary-title {
    font-weight: 600;
    font-size: 10px;
    color: #1976d2;
    margin-bottom: 6px;
    display: flex;
    align-items: center;
    gap: 5px;
}

.booking-summary-apt .summary-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 6px;
}

.booking-summary-apt .summary-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 4px 6px;
    background: rgba(255,255,255,0.8);
    border-radius: 4px;
    font-size: 10px;
    text-align: center;
}

.booking-summary-apt .summary-label {
    color: #78909c;
    font-weight: 500;
    font-size: 9px;
    margin-bottom: 2px;
}

.booking-summary-apt .summary-value {
    color: #1565c0;
    font-weight: 700;
    font-size: 11px;
}

.booking-summary-apt.has-overlap {
    background: linear-gradient(135deg, #ffebee 0%, #ffcdd2 100%);
    border-color: #e57373;
}

.booking-summary-apt.has-overlap .summary-title,
.booking-summary-apt.has-overlap .summary-value {
    color: #c62828;
}

.apt-advanced-toggle {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0;
    margin-top: 10px;
    cursor: pointer;
    color: #78909c;
    font-size: 11px;
    font-weight: 600;
    border-top: 1px dashed #e0e0e0;
    transition: color 0.2s;
}

.apt-advanced-toggle:hover {
    color: #0078d4;
}

.apt-advanced-toggle span {
    display: flex;
    align-items: center;
    gap: 5px;
}

.apt-toggle-icon {
    transition: transform 0.3s;
    font-size: 10px;
}

.apt-advanced-toggle.open .apt-toggle-icon {
    transform: rotate(180deg);
}

.apt-advanced-options {
    display: none;
    padding-top: 10px;
}

.apt-advanced-options.open {
    display: block;
}

.apt-dialog-footer {
    padding: 10px 16px;
    background: #f5f7f9;
    border-top: 1px solid #e0e0e0;
    display: flex;
    gap: 8px;
    flex-direction: row-reverse;
    flex-shrink: 0;
}

.apt-btn-primary,
.apt-btn-secondary {
    padding: 8px 16px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 5px;
    transition: all 0.2s;
    border: none;
    font-family: inherit;
}

.apt-btn-primary {
    background: linear-gradient(135deg, #0078d4 0%, #0063b1 100%);
    color: white;
}

.apt-btn-primary:hover {
    background: linear-gradient(135deg, #0063b1 0%, #004c8c 100%);
}

.apt-btn-secondary {
    background: white;
    color: #546e7a;
    border: 1.5px solid #e0e0e0;
}

.apt-btn-secondary:hover {
    background: #f5f5f5;
    border-color: #bdbdbd;
}

.no-working-day {
    text-align: center;
    padding: 20px;
    color: #f44336;
    background: #ffebee;
    border-radius: 8px;
    font-size: 13px;
}

.slots-loading,
.apt-time-loading {
    text-align: center;
    padding: 15px;
    color: #757575;
    font-size: 12px;
}

.apt-time-legend {
    display: flex;
    gap: 12px;
    margin-top: 6px;
    font-size: 10px;
    color: #78909c;
}

.apt-time-legend span {
    display: flex;
    align-items: center;
    gap: 3px;
}

.apt-time-legend .legend-available i { color: #4caf50; font-size: 6px; }
.apt-time-legend .legend-busy i { color: #f44336; font-size: 6px; }
.apt-time-legend .legend-break i { color: #ff9800; font-size: 6px; }

@media (max-width: 480px) {
    .apt-form-grid {
        grid-template-columns: 1fr;
    }

    .apt-form-field.apt-field-full,
    .apt-form-field.apt-field-2col {
        grid-column: 1;
    }

    .booking-summary-apt .summary-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}
</style>

<?php if ($isRestrictedView && $hasOtherDoctorFolders): ?>
<!-- Multi-Doctor Patient Banner - Patient has folders with other doctors -->
<div class="restricted-access-banner" style="background: linear-gradient(135deg, #e3f2fd, #bbdefb); border: 2px solid #2196f3; border-radius: 12px; padding: 16px 20px; margin-bottom: 20px; display: flex; align-items: center; gap: 16px; box-shadow: 0 4px 12px rgba(33, 150, 243, 0.2);">
    <div style="width: 50px; height: 50px; border-radius: 12px; background: linear-gradient(135deg, #2196f3, #1976d2); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
        <i class="fas fa-user-friends" style="color: white; font-size: 22px;"></i>
    </div>
    <div style="flex: 1;">
        <div style="font-size: 15px; font-weight: 700; color: #1565c0; margin-bottom: 4px;">
            <i class="fas fa-info-circle" style="margin-left: 6px;"></i>
            نەخۆشی چەند دکتۆر
        </div>
        <div style="font-size: 13px; color: #0d47a1; line-height: 1.5;">
            ئەم نەخۆشە لای دکتۆرەکانی تر کەیسی هەیە. دەتوانیت کەیسی نوێی خۆت بۆ دروستبکەیت.
            <br>
            <span style="font-size: 12px; opacity: 0.85;">تەنها کەیسەکانی خۆت دەبینیت - زانیاری دکتۆرەکانی تر شاراوەتەوە.</span>
        </div>
    </div>
    <button onclick="openDialog('newCaseDialog')" class="btn" style="background: #2196f3; color: white; padding: 10px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; white-space: nowrap;">
        <i class="fas fa-briefcase-medical"></i>
        کەیسی نوێ دروستبکە
    </button>
</div>
<?php elseif ($isRestrictedView): ?>
<!-- New Patient Banner - No folders yet for this patient from anyone -->
<div class="restricted-access-banner" style="background: linear-gradient(135deg, #e8f5e9, #c8e6c9); border: 2px solid #4caf50; border-radius: 12px; padding: 16px 20px; margin-bottom: 20px; display: flex; align-items: center; gap: 16px; box-shadow: 0 4px 12px rgba(76, 175, 80, 0.2);">
    <div style="width: 50px; height: 50px; border-radius: 12px; background: linear-gradient(135deg, #4caf50, #388e3c); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
        <i class="fas fa-user-plus" style="color: white; font-size: 22px;"></i>
    </div>
    <div style="flex: 1;">
        <div style="font-size: 15px; font-weight: 700; color: #2e7d32; margin-bottom: 4px;">
            <i class="fas fa-plus-circle" style="margin-left: 6px;"></i>
            نەخۆشی نوێ بۆ تۆ
        </div>
        <div style="font-size: 13px; color: #1b5e20; line-height: 1.5;">
            هیچ کەیسێکت بۆ ئەم نەخۆشە نییە. کەیسی نوێ دروستبکە بۆ دەستپێکردنی کار.
        </div>
    </div>
    <button onclick="openDialog('newCaseDialog')" class="btn" style="background: #4caf50; color: white; padding: 10px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; white-space: nowrap;">
        <i class="fas fa-briefcase-medical"></i>
        کەیسی نوێ دروستبکە
    </button>
</div>
<?php endif; ?>

<?php if (isset($_GET['new'])): ?>
<div class="alert alert-success alert-new-patient" data-auto-hide>
    <i class="fas fa-check-circle alert-icon"></i>
    <div class="alert-content">نەخۆش بە سەرکەوتوویی تۆمارکرا</div>
</div>
<?php endif; ?>

<?php if (isset($_GET['updated'])): ?>
<div class="alert alert-success alert-new-patient" data-auto-hide>
    <i class="fas fa-check-circle alert-icon"></i>
    <div class="alert-content">زانیاری نەخۆش نوێکرایەوە</div>
</div>
<?php endif; ?>

<?php if (isset($_GET['appointment_created'])): ?>
<div class="alert alert-success alert-new-patient" data-auto-hide>
    <i class="fas fa-check-circle alert-icon"></i>
    <div class="alert-content">مەوعید بە سەرکەوتوویی دروستکرا</div>
</div>
<?php endif; ?>

<?php if ($appointmentCreateError !== ''): ?>
<div class="alert alert-danger" style="border-radius: 12px; margin-bottom: 20px;">
    <i class="fas fa-exclamation-triangle alert-icon"></i>
    <div class="alert-content"><?= htmlspecialchars($appointmentCreateError) ?></div>
</div>
<?php endif; ?>

<?php if (!empty($flashError)): ?>
<div class="alert alert-danger" style="border-radius: 12px; margin-bottom: 20px;">
    <i class="fas fa-exclamation-triangle alert-icon"></i>
    <div class="alert-content"><?= htmlspecialchars($flashError) ?></div>
</div>
<?php endif; ?>

<?php if (isset($_GET['folder_created'])): ?>
<div class="alert alert-success alert-new-patient" data-auto-hide>
    <i class="fas fa-check-circle alert-icon"></i>
    <div class="alert-content">دۆسیە بە سەرکەوتوویی دروستکرا</div>
</div>
<?php endif; ?>

<?php if (!empty($medicalConditions)): ?>
<!-- Medical Alert Banner - Enhanced -->
<div class="medical-alert-banner">
    <div class="medical-alert-header">
        <i class="fas fa-exclamation-triangle"></i>
        <span>ئاگادارکردنەوەی پزیشکی - ئەم نەخۆشە بار و دۆخی تایبەتی هەیە!</span>
    </div>
    <div class="medical-conditions-grid">
        <?php foreach ($medicalConditions as $condition): ?>
        <div class="medical-condition-card">
            <strong><?= $MEDICAL_CONDITIONS[$condition['condition_type']] ?? $condition['condition_type'] ?></strong>
            <?php if (!empty($condition['condition_details'])): ?>
            <div class="condition-details"><?= htmlspecialchars($condition['condition_details']) ?></div>
            <?php endif; ?>
            <div class="severity-badge">
                <i class="fas fa-<?= $condition['severity'] === 'high' ? 'exclamation-circle' : ($condition['severity'] === 'medium' ? 'exclamation-triangle' : 'info-circle') ?>"></i>
                <?= $condition['severity'] === 'high' ? 'زۆر' : ($condition['severity'] === 'medium' ? 'مامناوەند' : 'کەم') ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="patient-view-grid" style="display: grid; grid-template-columns: 360px 1fr; gap: 24px;">
    <!-- Patient Info Panel -->
    <div>
        <!-- Patient Profile Card - Modern -->
        <div class="patient-profile-card" style="margin-bottom: 20px;">
            <div class="patient-profile-header">
                <div class="patient-avatar-large <?= $patient['gender'] === 'female' ? 'female' : '' ?>">
                    <?= mb_substr($patient['full_name'], 0, 1) ?>
                </div>
                <h3 class="patient-name-title"><?= htmlspecialchars($patient['full_name']) ?></h3>
                <div class="patient-code-badge">
                    <i class="fas fa-id-card"></i>
                    <?= htmlspecialchars($patient['patient_code']) ?>
                </div>
            </div>

            <div class="patient-quick-actions">
                <a href="tel:<?= htmlspecialchars($patient['phone']) ?>" class="btn btn-primary" style="flex: 1; font-size: 12px; padding: 8px;">
                    <i class="fas fa-phone"></i>
                    پەیوەندی
                </a>
                <?php // Edit is available to anyone who can open this profile — admin/
                      // reception, and a doctor who owns the patient OR was referred
                      // them by reception. edit.php re-checks this server-side. ?>
                <a href="<?= APP_URL ?>/patients/edit.php?id=<?= $patient['id'] ?>" class="btn btn-secondary" style="flex: 1; font-size: 12px; padding: 8px;">
                    <i class="fas fa-edit"></i>
                    دەستکاری
                </a>
            </div>

            <div class="patient-info-table">
                <div class="info-row">
                    <div class="info-label">
                        <i class="fas fa-venus-mars"></i>
                        ڕەگەز
                    </div>
                    <div class="info-value"><?= $patient['gender'] === 'male' ? 'نێر' : 'مێ' ?></div>
                </div>
                <?php if ($patient['date_of_birth']): ?>
                <div class="info-row">
                    <div class="info-label">
                        <i class="fas fa-birthday-cake"></i>
                        تەمەن
                    </div>
                    <div class="info-value">
                        <?php
                        $dob = new DateTime($patient['date_of_birth']);
                        $now = new DateTime();
                        $age = $now->diff($dob)->y;
                        echo $age . ' ساڵ';
                        ?>
                    </div>
                </div>
                <?php endif; ?>
                <div class="info-row">
                    <div class="info-label">
                        <i class="fas fa-mobile-alt"></i>
                        مۆبایل
                    </div>
                    <div class="info-value"><a href="tel:<?= htmlspecialchars($patient['phone']) ?>" style="color: inherit;"><?= htmlspecialchars($patient['phone']) ?></a></div>
                </div>
                <?php if (!empty($patient['phone2'])): ?>
                <div class="info-row">
                    <div class="info-label">
                        <i class="fas fa-phone-alt"></i>
                        مۆبایل ٢
                    </div>
                    <div class="info-value"><a href="tel:<?= htmlspecialchars($patient['phone2']) ?>" style="color: inherit;"><?= htmlspecialchars($patient['phone2']) ?></a></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($patient['city'])): ?>
                <div class="info-row">
                    <div class="info-label">
                        <i class="fas fa-city"></i>
                        شار
                    </div>
                    <div class="info-value"><?= htmlspecialchars($patient['city']) ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($patient['blood_type'])): ?>
                <div class="info-row">
                    <div class="info-label">
                        <i class="fas fa-tint"></i>
                        جۆری خوێن
                    </div>
                    <div class="info-value"><span class="badge badge-red"><?= $patient['blood_type'] ?></span></div>
                </div>
                <?php endif; ?>
                <div class="info-row">
                    <div class="info-label">
                        <i class="fas fa-calendar-plus"></i>
                        تۆمارکراو
                    </div>
                    <div class="info-value"><?= date('Y-m-d', strtotime($patient['created_at'])) ?></div>
                </div>
                <?php if ($nextAppointmentRow): ?>
                <div class="info-row" style="background: #f0f7ff; margin: 0 -16px; padding-left: 16px; padding-right: 16px; border-radius: 0;">
                    <div class="info-label">
                        <i class="fas fa-calendar-check" style="color: #0078d4;"></i>
                        مەوعیدی داهاتوو
                    </div>
                    <div class="info-value" style="color: #0078d4;">
                        <?= date('Y-m-d', strtotime($nextAppointmentRow['appointment_date'])) ?>
                        - <?= date('H:i', strtotime($nextAppointmentRow['appointment_time'])) ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Statistics - Modern (Hidden in restricted view) -->
        <?php if (!$isRestrictedView): ?>
        <div class="patient-profile-card">
            <div class="stats-grid-modern">
                <div class="stat-card-modern stat-visits">
                    <div class="stat-icon-modern">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <div class="stat-value-modern"><?= $statistics['total_visits'] ?></div>
                    <div class="stat-label-modern">سەردان</div>
                </div>
                <div class="stat-card-modern stat-folders">
                    <div class="stat-icon-modern">
                        <i class="fas fa-briefcase-medical"></i>
                    </div>
                    <div class="stat-value-modern"><?= count($patientCases) ?></div>
                    <div class="stat-label-modern">کەیس</div>
                </div>
                <div class="stat-card-modern stat-paid">
                    <div class="stat-icon-modern">
                        <i class="fas fa-coins"></i>
                    </div>
                    <div class="stat-value-modern"><?= number_format($statistics['total_paid']) ?></div>
                    <div class="stat-label-modern">پارەی دراو</div>
                </div>
                <div class="stat-card-modern stat-debt" <?php if ($xrayDebt > 0): ?>title="دۆسیەکان: <?= number_format($statistics['total_debt'] - $xrayDebt) ?> | ئێکسڕەی: <?= number_format($xrayDebt) ?>"<?php endif; ?>>
                    <div class="stat-icon-modern">
                        <i class="fas fa-hand-holding-usd"></i>
                    </div>
                    <div class="stat-value-modern"><?= number_format($statistics['total_debt']) ?></div>
                    <div class="stat-label-modern">
                        قەرزی گشتی
                        <?php if ($xrayDebt > 0): ?>
                        <small style="display: block; font-size: 10px; opacity: 0.8; margin-top: 2px;">
                            (دۆسیە: <?= number_format($statistics['total_debt'] - $xrayDebt) ?> + ئێکسڕەی: <?= number_format($xrayDebt) ?>)
                        </small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Main Content -->
    <div>
        <!-- Tabs -->
        <div class="panel">
            <div class="tabs-container">
                <?php if ($isDoctor && !$isAdmin): ?>
                <!-- Doctor view: Always show cases tab (their own cases only) -->
                <button class="tab active" onclick="showTab('folders')">
                    <i class="fas fa-briefcase-medical"></i> کەیسەکانم (<?= count($patientCases) ?>)
                </button>
                <button class="tab" onclick="showTab('appointments')">
                    <i class="fas fa-calendar"></i> مەوعیدەکانم (<?= count($appointments) ?>)
                </button>
                <button class="tab" onclick="showTab('xray')">
                    <i class="fas fa-x-ray"></i> ئێکسڕەی <?php if ($canViewXray): ?>(<?= $xrayCount ?>)<?php endif; ?>
                </button>
                <?php if (!$isRestrictedView): ?>
                <button class="tab" onclick="showTab('family')">
                    <i class="fas fa-users"></i> خێزان (<?= count($familyMembers) ?>)
                </button>
                <button class="tab" onclick="showTab('notes')">
                    <i class="fas fa-sticky-note"></i> تێبینی
                </button>
                <?php endif; ?>
                <?php else: ?>
                <!-- Admin/Staff view: See all tabs -->
                <button class="tab active" onclick="showTab('folders')">
                    <i class="fas fa-briefcase-medical"></i> کەیسەکان (<?= count($patientCases) ?>)
                </button>
                <button class="tab" onclick="showTab('appointments')">
                    <i class="fas fa-calendar"></i> مەوعیدەکان (<?= count($appointments) ?>)
                </button>
                <button class="tab" onclick="showTab('family')">
                    <i class="fas fa-users"></i> خێزان (<?= count($familyMembers) ?>)
                </button>
                <button class="tab" onclick="showTab('notes')">
                    <i class="fas fa-sticky-note"></i> تێبینی
                </button>
                <button class="tab" onclick="showTab('xray')">
                    <i class="fas fa-x-ray"></i> ئێکسڕەی (<?= $xrayCount ?>)
                </button>
                <?php endif; ?>
            </div>

            <!-- Cases Tab (Auto-Folder: cases shown directly on the patient) -->
            <div class="tab-content active" id="tab-folders">
                <div class="panel-body">
                    <div class="d-flex justify-between align-center mb-3">
                        <h4>کەیسەکان</h4>
                        <button class="btn btn-primary" onclick="openDialog('newCaseDialog')">
                            <i class="fas fa-plus"></i>
                            کەیسی نوێ
                        </button>
                    </div>

                    <?php if (!empty($caseCreateError)): ?>
                    <div class="alert alert-danger" data-auto-hide>
                        <i class="fas fa-exclamation-circle alert-icon"></i>
                        <div class="alert-content"><?= htmlspecialchars($caseCreateError) ?></div>
                    </div>
                    <?php endif; ?>

                    <?php if (empty($patientCases)): ?>
                    <div class="empty-state-modern">
                        <div class="empty-state-icon-modern">
                            <i class="fas fa-briefcase-medical"></i>
                        </div>
                        <p class="empty-state-title-modern">هیچ کەیسێک نییە</p>
                        <p class="empty-state-text-modern">ئەم نەخۆشە هێشتا هیچ کەیسێکی نییە. کەیسێکی نوێ دروست بکە بۆ دەستپێکردنی چارەسەر</p>
                        <button class="btn btn-primary" onclick="openDialog('newCaseDialog')" style="padding: 11px 24px; font-size: 14px; border-radius: 10px;">
                            <i class="fas fa-plus-circle"></i>
                            دروستکردنی کەیس
                        </button>
                    </div>
                    <?php else: ?>
                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 16px;">
                        <?php foreach ($patientCases as $case): ?>
                        <?php
                            $caseRemaining = $case['total_amount'] - $case['paid_amount'] - $case['discount_amount'];
                            $caseStatusLabels = ['open' => 'کراوە', 'pending' => 'چاوەڕوان', 'closed' => 'داخراو'];
                        ?>
                        <div class="folder-card <?= $case['status'] === 'closed' ? 'status-closed' : '' ?>" onclick="window.location='<?= APP_URL ?>/cases/view.php?id=<?= $case['id'] ?>'">
                            <div class="folder-header">
                                <div class="folder-icon-box">
                                    <i class="fas fa-briefcase-medical"></i>
                                </div>
                                <div class="folder-info">
                                    <h5 class="folder-title"><?= htmlspecialchars($case['case_name'] ?: $case['case_number']) ?></h5>
                                    <div class="folder-doctor">
                                        <i class="fas fa-user-md"></i>
                                        <?= htmlspecialchars($case['doctor_name'] ?? '') ?>
                                    </div>
                                </div>
                                <span class="badge <?= $case['status'] === 'open' ? 'badge-green' : 'badge-secondary' ?>" style="flex-shrink: 0;">
                                    <?= $caseStatusLabels[$case['status']] ?? $case['status'] ?>
                                </span>
                            </div>
                            <div class="folder-meta">
                                <span><i class="fas fa-hashtag"></i> <?= htmlspecialchars($case['case_number']) ?></span>
                                <span><i class="fas fa-calendar"></i> <?= date('Y-m-d', strtotime($case['created_at'])) ?></span>
                            </div>
                            <?php if ($caseRemaining > 0): ?>
                            <div class="folder-progress">
                                <div class="progress-info">
                                    <span class="progress-label">پارەی دراو</span>
                                    <span class="progress-amount"><?= number_format($caseRemaining) ?> ماوە</span>
                                </div>
                                <div class="progress-bar-container">
                                    <?php
                                    // Measure against what is actually owed (total − discount), capped 0–100
                                    $caseEffectiveTotal = max(0, (float)$case['total_amount'] - (float)$case['discount_amount']);
                                    $casePercent = $caseEffectiveTotal > 0 ? min(100, max(0, ((float)$case['paid_amount'] / $caseEffectiveTotal) * 100)) : 0;
                                    ?>
                                    <div class="progress-bar-fill" style="width: <?= $casePercent ?>%;"></div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Appointments Tab -->
            <div class="tab-content" id="tab-appointments" style="display: none;">
                <div class="panel-body">
                    <div class="d-flex justify-between align-center mb-3">
                        <h4>مەوعیدەکان</h4>
                        <button type="button" class="btn btn-primary" onclick="openPatientAppointmentDialog()">
                            <i class="fas fa-plus"></i>
                            مەوعیدی نوێ
                        </button>
                    </div>

                    <?php if (empty($appointments)): ?>
                    <div class="empty-state-modern">
                        <div class="empty-state-icon-modern" style="background: linear-gradient(135deg, #fff3e0, #ffe0b2); color: #f57c00;">
                            <i class="fas fa-calendar-times"></i>
                        </div>
                        <p class="empty-state-title-modern">هیچ مەوعیدێک نییە</p>
                        <p class="empty-state-text-modern">ئەم نەخۆشە هیچ مەوعیدێکی نییە</p>
                        <button type="button" class="btn btn-primary" onclick="openPatientAppointmentDialog()" style="padding: 11px 24px; font-size: 14px; border-radius: 10px;">
                            <i class="fas fa-plus-circle"></i> دروستکردنی مەوعید
                        </button>
                    </div>
                    <?php else: ?>
                    <?php
                    // Separate upcoming and past appointments
                    $today = date('Y-m-d');
                    $upcomingApts = [];
                    $pastApts = [];
                    foreach ($appointments as $apt) {
                        if ($apt['appointment_date'] >= $today && !in_array($apt['status'], ['completed', 'cancelled', 'no_show'])) {
                            $upcomingApts[] = $apt;
                        } else {
                            $pastApts[] = $apt;
                        }
                    }
                    ?>

                    <?php if (!empty($upcomingApts)): ?>
                    <div class="apt-section-header">
                        <i class="fas fa-clock"></i> مەوعیدە داهاتووەکان
                        <span class="apt-section-count"><?= count($upcomingApts) ?></span>
                    </div>
                    <div class="appointments-cards-grid">
                        <?php foreach ($upcomingApts as $apt):
                            $statusStyles = [
                                'scheduled' => ['bg' => '#e3f2fd', 'border' => '#2196f3', 'color' => '#1565c0', 'icon' => 'calendar-alt', 'label' => 'داڕێژراو'],
                                'confirmed' => ['bg' => '#e8f5e9', 'border' => '#4caf50', 'color' => '#2e7d32', 'icon' => 'check-circle', 'label' => 'پشتڕاستکراو'],
                                'waiting' => ['bg' => '#fff3e0', 'border' => '#ff9800', 'color' => '#e65100', 'icon' => 'user-clock', 'label' => 'چاوەڕوان'],
                                'in_progress' => ['bg' => '#e3f2fd', 'border' => '#2196f3', 'color' => '#1565c0', 'icon' => 'procedures', 'label' => 'لە چارەسەردا']
                            ];
                            $style = $statusStyles[$apt['status']] ?? $statusStyles['scheduled'];

                            $typeLabels = [
                                'checkup' => ['icon' => 'stethoscope', 'label' => 'پشکنین'],
                                'treatment' => ['icon' => 'tooth', 'label' => 'چارەسەر'],
                                'followup' => ['icon' => 'redo', 'label' => 'بەدواداچوون'],
                                'emergency' => ['icon' => 'ambulance', 'label' => 'فریاکەوتن'],
                                'consultation' => ['icon' => 'comments', 'label' => 'ڕاوێژ']
                            ];
                            $typeInfo = $typeLabels[$apt['type']] ?? ['icon' => 'calendar', 'label' => $apt['type']];

                            $isToday = $apt['appointment_date'] === $today;
                            $isTomorrow = $apt['appointment_date'] === date('Y-m-d', strtotime('+1 day'));
                        ?>
                        <div class="apt-card <?= $isToday ? 'apt-card-today' : '' ?>" style="border-right-color: <?= $style['border'] ?>;">
                            <?php if ($isToday): ?>
                            <div class="apt-card-ribbon">ئەمڕۆ</div>
                            <?php elseif ($isTomorrow): ?>
                            <div class="apt-card-ribbon apt-ribbon-tomorrow">سبەینێ</div>
                            <?php endif; ?>
                            <div class="apt-card-header">
                                <div class="apt-card-datetime">
                                    <div class="apt-card-date">
                                        <i class="fas fa-calendar"></i>
                                        <?= date('Y-m-d', strtotime($apt['appointment_date'])) ?>
                                    </div>
                                    <div class="apt-card-time">
                                        <i class="fas fa-clock"></i>
                                        <?= date('H:i', strtotime($apt['appointment_time'])) ?>
                                    </div>
                                </div>
                                <div class="apt-card-status" style="background: <?= $style['bg'] ?>; color: <?= $style['color'] ?>; border-color: <?= $style['border'] ?>;">
                                    <i class="fas fa-<?= $style['icon'] ?>"></i>
                                    <?= $style['label'] ?>
                                </div>
                            </div>
                            <div class="apt-card-body">
                                <div class="apt-card-info-row">
                                    <span class="apt-card-label"><i class="fas fa-user-md"></i> دکتۆر</span>
                                    <span class="apt-card-value">د. <?= htmlspecialchars($apt['doctor_name']) ?></span>
                                </div>
                                <div class="apt-card-info-row">
                                    <span class="apt-card-label"><i class="fas fa-<?= $typeInfo['icon'] ?>"></i> جۆر</span>
                                    <span class="apt-card-value"><?= $typeInfo['label'] ?></span>
                                </div>
                                <?php if (!empty($apt['duration'])): ?>
                                <div class="apt-card-info-row">
                                    <span class="apt-card-label"><i class="fas fa-hourglass-half"></i> ماوە</span>
                                    <span class="apt-card-value"><?= $apt['duration'] ?> خولەک</span>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($apt['notes'])): ?>
                                <div class="apt-card-notes">
                                    <i class="fas fa-sticky-note"></i> <?= htmlspecialchars(mb_substr($apt['notes'], 0, 50)) ?><?= mb_strlen($apt['notes']) > 50 ? '...' : '' ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="apt-card-actions">
                                <?php if ($apt['status'] === 'scheduled'): ?>
                                <a href="<?= APP_URL ?>/appointments.php?confirm=<?= $apt['id'] ?>" class="apt-action-btn apt-action-confirm" title="پشتڕاستکردنەوە">
                                    <i class="fas fa-check"></i>
                                </a>
                                <?php endif; ?>
                                <?php if (in_array($apt['status'], ['scheduled', 'confirmed'])): ?>
                                <a href="<?= APP_URL ?>/appointments.php?checkin=<?= $apt['id'] ?>" class="apt-action-btn apt-action-checkin" title="ناردن بۆ چاوەڕوانی">
                                    <i class="fas fa-user-check"></i>
                                </a>
                                <?php endif; ?>
                                <a href="tel:<?= htmlspecialchars($patient['phone']) ?>" class="apt-action-btn apt-action-call" title="پەیوەندی">
                                    <i class="fas fa-phone"></i>
                                </a>
                                <a href="<?= APP_URL ?>/appointments.php?cancel=<?= $apt['id'] ?>" class="apt-action-btn apt-action-cancel" title="هەڵوەشاندنەوە" onclick="return confirm('دڵنیایت لە هەڵوەشاندنەوەی مەوعید؟')">
                                    <i class="fas fa-times"></i>
                                </a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($pastApts)): ?>
                    <div class="apt-section-header apt-section-past">
                        <i class="fas fa-history"></i> مەوعیدە پێشووەکان
                        <span class="apt-section-count"><?= count($pastApts) ?></span>
                    </div>
                    <div class="appointments-cards-grid">
                        <?php foreach (array_slice($pastApts, 0, 10) as $apt):
                            $statusStyles = [
                                'completed' => ['bg' => '#e8f5e9', 'border' => '#4caf50', 'color' => '#2e7d32', 'icon' => 'check-double', 'label' => 'تەواوبوو'],
                                'cancelled' => ['bg' => '#ffebee', 'border' => '#f44336', 'color' => '#c62828', 'icon' => 'times-circle', 'label' => 'هەڵوەشاوە'],
                                'no_show' => ['bg' => '#ffebee', 'border' => '#f44336', 'color' => '#c62828', 'icon' => 'user-slash', 'label' => 'نەهات'],
                                'scheduled' => ['bg' => '#f5f5f5', 'border' => '#9e9e9e', 'color' => '#616161', 'icon' => 'calendar-alt', 'label' => 'تێپەڕیو'],
                                'confirmed' => ['bg' => '#f5f5f5', 'border' => '#9e9e9e', 'color' => '#616161', 'icon' => 'calendar-alt', 'label' => 'تێپەڕیو']
                            ];
                            $style = $statusStyles[$apt['status']] ?? $statusStyles['scheduled'];

                            $typeLabels = [
                                'checkup' => ['icon' => 'stethoscope', 'label' => 'پشکنین'],
                                'treatment' => ['icon' => 'tooth', 'label' => 'چارەسەر'],
                                'followup' => ['icon' => 'redo', 'label' => 'بەدواداچوون'],
                                'emergency' => ['icon' => 'ambulance', 'label' => 'فریاکەوتن'],
                                'consultation' => ['icon' => 'comments', 'label' => 'ڕاوێژ']
                            ];
                            $typeInfo = $typeLabels[$apt['type']] ?? ['icon' => 'calendar', 'label' => $apt['type']];
                        ?>
                        <div class="apt-card apt-card-past" style="border-right-color: <?= $style['border'] ?>;">
                            <div class="apt-card-header">
                                <div class="apt-card-datetime">
                                    <div class="apt-card-date">
                                        <i class="fas fa-calendar"></i>
                                        <?= date('Y-m-d', strtotime($apt['appointment_date'])) ?>
                                    </div>
                                    <div class="apt-card-time">
                                        <i class="fas fa-clock"></i>
                                        <?= date('H:i', strtotime($apt['appointment_time'])) ?>
                                    </div>
                                </div>
                                <div class="apt-card-status" style="background: <?= $style['bg'] ?>; color: <?= $style['color'] ?>; border-color: <?= $style['border'] ?>;">
                                    <i class="fas fa-<?= $style['icon'] ?>"></i>
                                    <?= $style['label'] ?>
                                </div>
                            </div>
                            <div class="apt-card-body">
                                <div class="apt-card-info-row">
                                    <span class="apt-card-label"><i class="fas fa-user-md"></i> دکتۆر</span>
                                    <span class="apt-card-value">د. <?= htmlspecialchars($apt['doctor_name']) ?></span>
                                </div>
                                <div class="apt-card-info-row">
                                    <span class="apt-card-label"><i class="fas fa-<?= $typeInfo['icon'] ?>"></i> جۆر</span>
                                    <span class="apt-card-value"><?= $typeInfo['label'] ?></span>
                                </div>
                            </div>
                            <?php if ($apt['status'] !== 'completed'): ?>
                            <div class="apt-card-actions">
                                <a href="<?= APP_URL ?>/appointments.php?reschedule=<?= $apt['id'] ?>&patient_id=<?= $patient['id'] ?>" class="apt-action-btn apt-action-reschedule" title="مەوعید بۆ دووبارە">
                                    <i class="fas fa-redo"></i> مەوعیدی نوێ
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if (count($pastApts) > 10): ?>
                    <div class="apt-show-more">
                        <a href="<?= APP_URL ?>/appointments.php?patient_id=<?= $patient['id'] ?>" class="btn btn-secondary btn-sm">
                            <i class="fas fa-list"></i> بینینی هەموو (<?= count($pastApts) ?>)
                        </a>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>

                    <?php endif; ?>
                </div>
            </div>

            <!-- Family Tab -->
            <div class="tab-content" id="tab-family" style="display: none;">
                <div class="panel-body">
                    <div class="d-flex justify-between align-center mb-3">
                        <h4>ئەندامانی خێزان</h4>
                    </div>

                    <?php if (empty($familyMembers)): ?>
                    <div class="empty-state-modern">
                        <div class="empty-state-icon-modern" style="background: linear-gradient(135deg, #f3e5f5, #e1bee7); color: #8e24aa;">
                            <i class="fas fa-users"></i>
                        </div>
                        <p class="empty-state-title-modern">هیچ خزمێک تۆمار نەکراوە</p>
                        <p class="empty-state-text-modern">دەتوانیت نەخۆشە خزمەکانی ئەم نەخۆشە بەیەکەوە ببەستیتەوە بۆ داشکاندنی خێزانی</p>
                    </div>
                    <?php else: ?>
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>ناو</th>
                                    <th>پەیوەندی</th>
                                    <th>مۆبایل</th>
                                    <th>کردار</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($familyMembers as $member): ?>
                                <tr>
                                    <td>
                                        <a href="<?= APP_URL ?>/patients/view.php?id=<?= $member['related_patient_id'] ?>">
                                            <?= htmlspecialchars($member['full_name']) ?>
                                        </a>
                                    </td>
                                    <td><?= htmlspecialchars($member['relationship']) ?></td>
                                    <td><?= htmlspecialchars($member['phone'] ?? '') ?></td>
                                    <td>
                                        <a href="<?= APP_URL ?>/patients/view.php?id=<?= $member['related_patient_id'] ?>" class="btn btn-sm btn-secondary">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Notes Tab -->
            <div class="tab-content" id="tab-notes" style="display: none;">
                <div class="panel-body">
                    <h4>تێبینییەکان</h4>
                    <?php if (!empty($patient['notes'])): ?>
                    <div class="alert alert-info">
                        <?= nl2br(htmlspecialchars($patient['notes'])) ?>
                    </div>
                    <?php else: ?>
                    <p class="text-secondary">هیچ تێبینییەک نییە</p>
                    <?php endif; ?>

                    <?php if (!empty($patient['address'])): ?>
                    <h5 class="mt-3">ناونیشان</h5>
                    <p><?= nl2br(htmlspecialchars($patient['address'])) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- X-Ray Tab -->
            <div class="tab-content" id="tab-xray" style="display: none;">
                <div class="panel-body">
                    <?php global $XRAY_TYPES, $XRAY_STATUS; ?>
                    <div class="d-flex justify-between align-center mb-3">
                        <h4>ئێکسڕەی</h4>
                        <?php if ($auth->canRequestXray()): ?>
                        <a href="<?= APP_URL ?>/xray/request.php?patient_id=<?= $patientId ?>" class="btn btn-primary">
                            <i class="fas fa-plus"></i> داواکاری نوێ
                        </a>
                        <?php endif; ?>
                    </div>

                    <?php if (!$canViewXray): ?>
                    <!-- Doctor doesn't have permission to view X-ray history, but can request -->
                    <div class="empty-state-modern">
                        <div class="empty-state-icon-modern" style="background: linear-gradient(135deg, #fff3e0, #ffe0b2); color: #ff9800;">
                            <i class="fas fa-lock"></i>
                        </div>
                        <p class="empty-state-title-modern">مێژووی ئێکسڕەی بەردەست نییە</p>
                        <p class="empty-state-text-modern">تۆ ڕێگەت نییە مێژووی ئێکسڕەیەکانی ئەم نەخۆشە ببینیت، بەڵام دەتوانیت داواکاری ئێکسڕەی نوێ بکەیت</p>
                        <?php if ($auth->canRequestXray()): ?>
                        <a href="<?= APP_URL ?>/xray/request.php?patient_id=<?= $patientId ?>" class="btn btn-primary" style="padding: 11px 24px; font-size: 14px; border-radius: 10px;">
                            <i class="fas fa-plus-circle"></i> داواکاری ئێکسڕەی نوێ
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php elseif (empty($patientXrays)): ?>
                    <div class="empty-state-modern">
                        <div class="empty-state-icon-modern" style="background: linear-gradient(135deg, #e0f2f1, #b2dfdb); color: #00897b;">
                            <i class="fas fa-x-ray"></i>
                        </div>
                        <p class="empty-state-title-modern">هیچ داواکاریی ئێکسڕەیەک نییە</p>
                        <p class="empty-state-text-modern">ئەم نەخۆشە هیچ ئێکسڕەیەکی نییە</p>
                        <?php if ($auth->canRequestXray()): ?>
                        <a href="<?= APP_URL ?>/xray/request.php?patient_id=<?= $patientId ?>" class="btn btn-primary" style="padding: 11px 24px; font-size: 14px; border-radius: 10px;">
                            <i class="fas fa-plus-circle"></i> داواکاری ئێکسڕەی
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="xray-cards-grid">
                        <?php foreach ($patientXrays as $xray):
                            $statusColors = [
                                'pending' => ['bg' => '#fff8e1', 'border' => '#ffcc02', 'color' => '#f57f17', 'icon' => 'clock'],
                                'in_progress' => ['bg' => '#e3f2fd', 'border' => '#2196f3', 'color' => '#1565c0', 'icon' => 'spinner fa-spin'],
                                'completed' => ['bg' => '#e8f5e9', 'border' => '#4caf50', 'color' => '#2e7d32', 'icon' => 'check-circle'],
                                'cancelled' => ['bg' => '#ffebee', 'border' => '#f44336', 'color' => '#c62828', 'icon' => 'times-circle']
                            ];
                            $statusStyle = $statusColors[$xray['status']] ?? $statusColors['pending'];

                            $paymentStatusColors = [
                                'pending' => ['bg' => '#f5f5f5', 'text' => '#757575', 'label' => 'چاوەڕوان'],
                                'awaiting_payment' => ['bg' => '#fff3e0', 'text' => '#e65100', 'label' => 'چاوەڕوانی پارەدان'],
                                'paid' => ['bg' => '#e8f5e9', 'text' => '#2e7d32', 'label' => 'پارەدراو'],
                                'debt' => ['bg' => '#ffebee', 'text' => '#c62828', 'label' => 'قەرز']
                            ];
                            $paymentStyle = $paymentStatusColors[$xray['payment_status'] ?? 'pending'] ?? $paymentStatusColors['pending'];
                        ?>
                        <div class="xray-card" onclick="window.location='<?= APP_URL ?>/xray/view.php?id=<?= $xray['id'] ?>'" style="border-right-color: <?= $statusStyle['border'] ?>;">
                            <div class="xray-card-header">
                                <div class="xray-card-icon" style="background: <?= $statusStyle['bg'] ?>; color: <?= $statusStyle['color'] ?>;">
                                    <i class="fas fa-x-ray"></i>
                                </div>
                                <div class="xray-card-info">
                                    <div class="xray-card-number"><?= htmlspecialchars($xray['request_number']) ?></div>
                                    <div class="xray-card-type"><?= $XRAY_TYPES[$xray['xray_type']] ?? $xray['xray_type'] ?></div>
                                </div>
                                <div class="xray-card-status" style="background: <?= $statusStyle['bg'] ?>; color: <?= $statusStyle['color'] ?>; border-color: <?= $statusStyle['border'] ?>;">
                                    <i class="fas fa-<?= $statusStyle['icon'] ?>"></i>
                                    <?= $XRAY_STATUS[$xray['status']] ?? $xray['status'] ?>
                                </div>
                            </div>
                            <div class="xray-card-body">
                                <div class="xray-card-meta">
                                    <span><i class="fas fa-calendar"></i> <?= date('Y-m-d', strtotime($xray['created_at'])) ?></span>
                                    <span><i class="fas fa-user-md"></i> <?= htmlspecialchars($xray['requested_by_name'] ?? 'نەزانراو') ?></span>
                                </div>
                                <?php if ($xray['status'] === 'completed' && isset($xray['payment_status'])): ?>
                                <div class="xray-card-payment">
                                    <span class="xray-payment-badge" style="background: <?= $paymentStyle['bg'] ?>; color: <?= $paymentStyle['text'] ?>;">
                                        <i class="fas fa-<?= $xray['payment_status'] === 'paid' ? 'check' : ($xray['payment_status'] === 'debt' ? 'exclamation-triangle' : 'clock') ?>"></i>
                                        <?= $paymentStyle['label'] ?>
                                        <?php if (($xray['payment_status'] === 'debt' || $xray['payment_status'] === 'paid') && !empty($xray['final_amount'])): ?>
                                        - <?= number_format($xray['final_amount']) ?>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="xray-card-action">
                                <i class="fas fa-chevron-left"></i>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- New Appointment Dialog -->
<div class="dialog-overlay" id="newAppointmentDialog">
    <div class="dialog apt-dialog-modern">
        <div class="apt-dialog-header">
            <h4><i class="fas fa-calendar-plus"></i> مەوعیدی نوێ</h4>
            <button type="button" class="apt-dialog-close" onclick="closeDialog('newAppointmentDialog')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" action="" id="patientAppointmentForm">
            <input type="hidden" name="action" value="create_appointment">
            <input type="hidden" name="patient_id" id="selectedPatientId" value="<?= $patient['id'] ?>">
            <input type="hidden" name="appointment_time" id="appointmentTime" required>
            <input type="hidden" name="end_time" id="appointmentEndTime">

            <div class="apt-dialog-body">
                <div class="apt-form-grid">
                    <div class="apt-form-field apt-field-2col">
                        <label><i class="fas fa-user"></i> نەخۆش <span class="required">*</span></label>
                        <div id="selectedPatientDisplay" class="apt-selected-patient">
                            <div style="width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(135deg, #0078d4, #005a9e); color: white; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 14px;"><?= htmlspecialchars(mb_substr($patient['full_name'], 0, 1)) ?></div>
                            <div style="flex: 1;">
                                <div style="font-weight: 600; color: #0078d4; font-size: 14px;"><?= htmlspecialchars($patient['full_name']) ?></div>
                                <div style="font-size: 11px; color: #757575; direction: ltr; text-align: right;"><?= htmlspecialchars($patient['phone'] ?: 'بێ ژمارە') ?></div>
                            </div>
                        </div>
                    </div>

                    <?php if ($hasFullAccess): ?>
                    <div class="apt-form-field">
                        <label><i class="fas fa-user-md"></i> دکتۆر <span class="required">*</span></label>
                        <select name="doctor_id" id="appointmentDoctorId" required onchange="refreshPatientAppointmentTimePicker()">
                            <?php foreach ($doctorOptions as $doctorOption): ?>
                            <?php if (isset($doctorOption['is_active']) && !$doctorOption['is_active'] && (int)$doctorOption['id'] !== (int)$currentUser['id']) continue; ?>
                            <option value="<?= $doctorOption['id'] ?>" <?= (int)$doctorOption['id'] === (int)$currentUser['id'] ? 'selected' : '' ?>>
                                د. <?= htmlspecialchars($doctorOption['full_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php else: ?>
                    <input type="hidden" name="doctor_id" id="appointmentDoctorId" value="<?= (int)$currentUser['id'] ?>">
                    <div class="apt-form-field">
                        <label><i class="fas fa-user-md"></i> دکتۆر</label>
                        <input type="text" value="<?= htmlspecialchars($currentUser['full_name']) ?>" readonly>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="apt-form-grid" style="margin-top: 10px;">
                    <div class="apt-form-field">
                        <label><i class="fas fa-calendar"></i> بەروار <span class="required">*</span></label>
                        <input type="date" name="appointment_date" id="appointmentDate" value="<?= $defaultAppointmentDate ?>" min="<?= date('Y-m-d') ?>" required onchange="refreshPatientAppointmentTimePicker()">
                    </div>
                    <div class="apt-form-field">
                        <label><i class="fas fa-hourglass-half"></i> ماوە</label>
                        <select name="duration" id="appointmentDuration">
                            <?php
                            if (!function_exists('dh_duration_options_patient_view')) {
                                function dh_duration_options_patient_view($selected = 30) {
                                    $durations = [15, 30, 45];
                                    for ($m = 60; $m <= 720; $m += 30) {
                                        $durations[] = $m;
                                    }
                                    $out = '';
                                    foreach ($durations as $duration) {
                                        $sel = ((int)$duration === (int)$selected) ? ' selected' : '';
                                        $label = $duration < 60 ? $duration . ' خولەک' : ((int)($duration / 60)) . (($duration % 60) ? ' کاتژمێر و ' . ($duration % 60) . ' خولەک' : ' کاتژمێر');
                                        $out .= '<option value="' . $duration . '"' . $sel . '>' . htmlspecialchars($label) . '</option>';
                                    }
                                    return $out;
                                }
                            }
                            echo dh_duration_options_patient_view(30);
                            ?>
                        </select>
                    </div>
                    <div class="apt-form-field">
                        <label><i class="fas fa-tag"></i> جۆر</label>
                        <select name="type">
                            <option value="general">گشتی</option>
                            <option value="checkup">پشکنین</option>
                            <option value="treatment">چارەسەر</option>
                        </select>
                    </div>
                </div>

                <div class="apt-form-grid" style="margin-top: 10px;">
                    <div class="apt-form-field">
                        <label><i class="fas fa-briefcase-medical"></i> ئیش</label>
                        <div class="work-type-combo" style="display: flex; gap: 4px;">
                            <select name="work_type_id" id="appointmentWorkType" style="flex: 1;">
                                <option value="">— هەڵبژێرە —</option>
                                <?php foreach ($workTypes as $workType): ?>
                                <option value="<?= $workType['id'] ?>"><?= htmlspecialchars($workType['work_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" onclick="openAddWorkTypeDialog()" title="زیادکردنی ئیشی نوێ"
                                    style="padding: 0 10px; background: #0078d4; color: #fff; border: 1px solid #0078d4; border-radius: 4px; cursor: pointer;">
                                <i class="fas fa-plus"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <div id="aptDebtSection" class="apt-form-grid" style="margin-top: 10px; display: none;">
                    <div class="apt-form-field">
                        <label><i class="fas fa-hand-holding-usd" style="color:#c62828;"></i> پارەی قەرز</label>
                        <div id="aptPatientDebt" style="padding: 8px 10px; background: #fff0f0; border: 1px solid #fca5a5; border-radius: 6px; font-weight: 700; color: #c62828; text-align: center; min-height: 36px; display: flex; align-items: center; justify-content: center;">
                            <span style="color:#9aa7b3; font-weight: 400;">—</span>
                        </div>
                    </div>
                    <div class="apt-form-field">
                        <label><i class="fas fa-money-bill-wave" style="color:#16a34a;"></i> بەڵێنی پارەی هێنراو</label>
                        <input type="number" name="promised_amount" id="aptPromisedAmount" min="0" step="500" placeholder="0" style="direction: ltr; text-align: left;">
                    </div>
                </div>

                <div class="existing-bookings-section" id="aptExistingBookingsSection" style="display: none;">
                    <label><i class="fas fa-calendar-check"></i> گیراوەکان</label>
                    <div id="aptExistingBookingsList" class="existing-bookings-list"></div>
                </div>

                <div class="apt-time-section" style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #eee;">
                    <label><i class="fas fa-clock"></i> کات <span class="required">*</span></label>
                    <div id="smartTimePickerContainer" class="apt-time-grid-container">
                        <div class="apt-time-loading"><i class="fas fa-spinner fa-spin"></i></div>
                    </div>
                    <div class="apt-time-legend">
                        <span class="legend-available"><i class="fas fa-circle"></i> بەردەست</span>
                        <span class="legend-busy"><i class="fas fa-circle"></i> گیراو</span>
                        <span class="legend-break"><i class="fas fa-circle"></i> پشوو</span>
                    </div>
                </div>

                <div class="booking-summary-apt" id="aptBookingSummary" style="display: none;">
                    <div class="summary-title"><i class="fas fa-info-circle"></i> پوختەی مەوعید</div>
                    <div class="summary-grid">
                        <div class="summary-item">
                            <span class="summary-label">بەروار</span>
                            <span class="summary-value" id="aptSummaryDate">-</span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">دەستپێک</span>
                            <span class="summary-value" id="aptSummaryStartTime">-</span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">کۆتایی</span>
                            <span class="summary-value" id="aptSummaryEndTime">-</span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">ماوە</span>
                            <span class="summary-value" id="aptSummaryDuration">-</span>
                        </div>
                    </div>
                </div>

                <div class="apt-advanced-toggle" onclick="toggleAppointmentNotes()">
                    <span><i class="fas fa-sticky-note"></i> تێبینی</span>
                    <i class="fas fa-chevron-down apt-toggle-icon" id="aptNotesToggleIcon"></i>
                </div>

                <div class="apt-advanced-options" id="aptAdvancedOptions">
                    <div class="apt-form-field">
                        <textarea name="notes" rows="3" placeholder="تێبینی..." style="width: 100%;"></textarea>
                    </div>
                </div>
            </div>

            <div class="apt-dialog-footer">
                <button type="submit" class="apt-btn-primary"><i class="fas fa-check"></i> دروستکردن</button>
                <button type="button" class="apt-btn-secondary" onclick="closeDialog('newAppointmentDialog')"><i class="fas fa-times"></i> هەڵوەشاندنەوە</button>
            </div>
        </form>
    </div>
</div>

<!-- Add Work Type Dialog -->
<div class="dialog-overlay" id="addWorkTypeDialog">
    <div class="dialog apt-dialog-modern" style="max-width: 400px;">
        <div class="apt-dialog-header">
            <h4><i class="fas fa-briefcase-medical"></i> زیادکردنی ئیشی نوێ</h4>
            <button type="button" class="apt-dialog-close" onclick="closeDialog('addWorkTypeDialog')"><i class="fas fa-times"></i></button>
        </div>
        <div class="apt-dialog-body">
            <div class="apt-form-field">
                <label><i class="fas fa-tag"></i> ناوی ئیش <span class="required">*</span></label>
                <input type="text" id="newWorkTypeName" placeholder="بنموونە: تیرشکی ددان">
            </div>
            <div id="addWorkTypeError" style="color: #f44336; font-size: 13px; margin-top: 6px; display: none;"></div>
        </div>
        <div class="apt-dialog-footer">
            <button type="button" class="apt-btn-primary" onclick="submitNewWorkType()"><i class="fas fa-check"></i> زیادکردن</button>
            <button type="button" class="apt-btn-secondary" onclick="closeDialog('addWorkTypeDialog')"><i class="fas fa-times"></i> هەڵوەشاندنەوە</button>
        </div>
    </div>
</div>

<!-- New Folder Dialog -->
<!-- Auto-Folder: cases are created directly on the patient; the folder level is resolved silently -->
<div class="dialog-overlay" id="newCaseDialog">
    <div class="dialog" style="max-width: 500px;">
        <div class="dialog-header">
            <h4 class="dialog-title">کەیسی نوێ</h4>
            <button class="dialog-close" onclick="closeDialog('newCaseDialog')">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="create_case">
            <div class="dialog-body">
                <div class="form-group">
                    <label class="form-label required">ناوی کەیس</label>
                    <input type="text" name="case_name" class="form-control" required
                           placeholder="نم: پڕکردنەوەی ددانی ١٦">
                </div>
                <?php if ($hasFullAccess): ?>
                <div class="form-group">
                    <label class="form-label required">دکتۆر</label>
                    <select name="doctor_id" class="form-control" required>
                        <?php foreach ($doctorOptions as $doctor): ?>
                        <?php if (empty($doctor['is_active'])) continue; ?>
                        <option value="<?= $doctor['id'] ?>">
                            <?= htmlspecialchars($doctor['full_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="form-group">
                    <label class="form-label required">جۆری بوار</label>
                    <div style="display: flex; gap: 10px;">
                        <label style="flex: 1; display: flex; align-items: center; gap: 8px; padding: 10px 14px; border: 1.5px solid #e0e0e0; border-radius: 8px; cursor: pointer;">
                            <input type="radio" name="scope_type" value="tooth" checked>
                            <span><i class="fas fa-tooth" style="color: #4caf50;"></i> ددان</span>
                        </label>
                        <label style="flex: 1; display: flex; align-items: center; gap: 8px; padding: 10px 14px; border: 1.5px solid #e0e0e0; border-radius: 8px; cursor: pointer;">
                            <input type="radio" name="scope_type" value="arch">
                            <span><i class="fas fa-teeth" style="color: #9c27b0;"></i> ئەرک (جاو)</span>
                        </label>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">سکاڵای سەرەکی</label>
                    <textarea name="chief_complaint" class="form-control" rows="2"
                              placeholder="چی ئازاری هەیە؟"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">تێبینی</label>
                    <textarea name="notes" class="form-control" rows="2"></textarea>
                </div>
            </div>
            <div class="dialog-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDialog('newCaseDialog')">هەڵوەشاندنەوە</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-plus"></i>
                    دروستکردن
                </button>
            </div>
        </form>
    </div>
</div>

<script>
const PATIENT_APPOINTMENT = {
    id: <?= (int)$patient['id'] ?>,
    name: <?= json_encode($patient['full_name'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
    phone: <?= json_encode($patient['phone'] ?? '', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
};
const INITIAL_PATIENT_TAB = <?= json_encode($initialTab, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const PATIENT_FORM_RESTORE = <?= json_encode($formRestore ?? null, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE) ?: 'null' ?>;
const SCHEDULE_API = '<?= APP_URL ?>/api/schedule.php';
let aptExistingBookings = [];

function showTab(tabName, tabButton = null) {
    document.querySelectorAll('.tab-content').forEach(t => {
        t.style.display = 'none';
        t.classList.remove('active');
    });
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));

    const targetTab = document.getElementById('tab-' + tabName);
    if (targetTab) {
        targetTab.style.display = 'block';
        targetTab.classList.add('active');
    }

    const activeButton = tabButton || Array.from(document.querySelectorAll('.tab')).find(btn => (btn.getAttribute('onclick') || '').includes("'" + tabName + "'"));
    if (activeButton) {
        activeButton.classList.add('active');
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text || '';
    return div.innerHTML;
}

function getAppointmentDoctorId() {
    const field = document.getElementById('appointmentDoctorId');
    return field ? field.value : '';
}

function resetPatientAppointmentState() {
    const form = document.getElementById('patientAppointmentForm');
    if (form) form.reset();

    const dateInput = document.getElementById('appointmentDate');
    const patientIdInput = document.getElementById('selectedPatientId');
    const timeInput = document.getElementById('appointmentTime');
    const endTimeInput = document.getElementById('appointmentEndTime');
    const existingSection = document.getElementById('aptExistingBookingsSection');
    const existingList = document.getElementById('aptExistingBookingsList');
    const bookingSummary = document.getElementById('aptBookingSummary');
    const notesBox = document.getElementById('aptAdvancedOptions');
    const notesToggle = document.querySelector('.apt-advanced-toggle');

    if (dateInput) dateInput.value = '<?= $defaultAppointmentDate ?>';
    if (patientIdInput) patientIdInput.value = PATIENT_APPOINTMENT.id;
    if (timeInput) timeInput.value = '';
    if (endTimeInput) endTimeInput.value = '';
    if (existingSection) existingSection.style.display = 'none';
    if (existingList) existingList.innerHTML = '';
    if (bookingSummary) {
        bookingSummary.style.display = 'none';
        bookingSummary.classList.remove('has-overlap');
    }
    if (notesBox) notesBox.classList.remove('open');
    if (notesToggle) notesToggle.classList.remove('open');
    aptExistingBookings = [];
}

function openPatientAppointmentDialog() {
    resetPatientAppointmentState();
    loadPatientDebt(PATIENT_APPOINTMENT.id);
    openDialog('newAppointmentDialog');
    setTimeout(refreshPatientAppointmentTimePicker, 100);
}

function toggleAppointmentNotes() {
    const notesBox = document.getElementById('aptAdvancedOptions');
    const toggle = document.querySelector('.apt-advanced-toggle');
    if (!notesBox || !toggle) return;
    notesBox.classList.toggle('open');
    toggle.classList.toggle('open');
}

function openAddWorkTypeDialog() {
    const input = document.getElementById('newWorkTypeName');
    const err = document.getElementById('addWorkTypeError');
    if (input) input.value = '';
    if (err) {
        err.style.display = 'none';
        err.textContent = '';
    }
    openDialog('addWorkTypeDialog');
    setTimeout(() => input && input.focus(), 50);
}

function submitNewWorkType() {
    const input = document.getElementById('newWorkTypeName');
    const err = document.getElementById('addWorkTypeError');
    const name = (input?.value || '').trim();
    if (!err) return;

    err.style.display = 'none';
    if (!name) {
        err.textContent = 'تکایە ناوی ئیش بنووسە';
        err.style.display = 'block';
        return;
    }

    fetch('<?= APP_URL ?>/api/work-types.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'add', work_name: name })
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) {
            err.textContent = data.message || 'هەڵەیەک ڕوویدا';
            err.style.display = 'block';
            return;
        }

        const select = document.getElementById('appointmentWorkType');
        if (select) {
            const option = document.createElement('option');
            option.value = data.id;
            option.textContent = data.work_name;
            option.selected = true;
            select.appendChild(option);
        }

        closeDialog('addWorkTypeDialog');
    })
    .catch(error => {
        err.textContent = 'کێشەی پەیوەندی: ' + error.message;
        err.style.display = 'block';
    });
}

function loadPatientDebt(patientId) {
    const section = document.getElementById('aptDebtSection');
    const debtBox = document.getElementById('aptPatientDebt');
    const promised = document.getElementById('aptPromisedAmount');
    if (!section || !debtBox) return;

    section.style.display = '';
    debtBox.innerHTML = '<i class="fas fa-spinner fa-spin" style="color:#9aa7b3;"></i>';
    if (promised) {
        promised.value = '';
        promised.disabled = false;
    }

    fetch(`<?= APP_URL ?>/api/patient-debt.php?id=${patientId}`)
        .then(r => r.json())
        .then(d => {
            if (!d.success) {
                debtBox.innerHTML = '<span style="color:#9aa7b3;font-weight:400;">-</span>';
                return;
            }

            const amount = Number(d.total_debt) || 0;
            if (amount > 0) {
                debtBox.style.background = '#fff0f0';
                debtBox.style.borderColor = '#fca5a5';
                debtBox.style.color = '#c62828';
                debtBox.textContent = amount.toLocaleString();
                if (promised) promised.disabled = false;
            } else {
                debtBox.style.background = '#f0fdf4';
                debtBox.style.borderColor = '#86efac';
                debtBox.style.color = '#15803d';
                debtBox.innerHTML = '<i class="fas fa-check-circle"></i> بێ قەرز';
                if (promised) {
                    promised.value = '';
                    promised.disabled = true;
                }
            }
        })
        .catch(() => {
            debtBox.innerHTML = '<span style="color:#9aa7b3;font-weight:400;">-</span>';
        });
}

async function refreshPatientAppointmentTimePicker() {
    const container = document.getElementById('smartTimePickerContainer');
    const dateInput = document.getElementById('appointmentDate');
    const durationSelect = document.getElementById('appointmentDuration');
    const existingSection = document.getElementById('aptExistingBookingsSection');
    const existingList = document.getElementById('aptExistingBookingsList');
    const bookingSummary = document.getElementById('aptBookingSummary');
    if (!container || !dateInput) return;

    const date = dateInput.value;
    const duration = durationSelect?.value || 30;
    const doctorId = getAppointmentDoctorId();

    aptExistingBookings = [];
    if (bookingSummary) bookingSummary.style.display = 'none';
    document.getElementById('appointmentTime').value = '';
    document.getElementById('appointmentEndTime').value = '';

    if (!date || !doctorId) {
        container.innerHTML = '<div class="no-working-day"><i class="fas fa-exclamation-circle"></i>تکایە دکتۆر و بەروار هەڵبژێرە</div>';
        if (existingSection) existingSection.style.display = 'none';
        return;
    }

    container.innerHTML = '<div class="slots-loading"><i class="fas fa-spinner fa-spin"></i> چاوەڕوان بە...</div>';

    try {
        const [slotsResponse, bookingsResponse] = await Promise.all([
            fetch(`${SCHEDULE_API}?action=get_all_slots&doctor_id=${doctorId}&date=${date}&duration=${duration}`),
            fetch(`${SCHEDULE_API}?action=get_bookings&doctor_id=${doctorId}&date=${date}`)
        ]);

        const slotsResult = await slotsResponse.json();
        const bookingsResult = await bookingsResponse.json();

        if (existingSection && existingList) {
            existingSection.style.display = 'block';
            if (bookingsResult.success && bookingsResult.data && bookingsResult.data.length > 0) {
                aptExistingBookings = bookingsResult.data;
                existingList.innerHTML = aptExistingBookings.map(b => `
                    <div class="existing-booking-item">
                        <i class="fas fa-clock"></i>
                        <span>${escapeHtml(b.start_time)} - ${escapeHtml(b.end_time)}</span>
                        <span class="patient-name" title="${escapeHtml(b.patient_name || '')}">${escapeHtml(b.patient_name || 'مەوعید')}</span>
                    </div>
                `).join('');
            } else {
                existingList.innerHTML = '<div class="no-bookings-message"><i class="fas fa-check-circle"></i> هێچ مەوعیدێک نییە</div>';
            }
        }

        if (!slotsResult.success) {
            container.innerHTML = `<div class="no-working-day"><i class="fas fa-exclamation-circle"></i>${slotsResult.message || 'هەڵەیەک ڕوویدا'}</div>`;
            return;
        }

        const data = slotsResult.data;
        if (!data.working) {
            container.innerHTML = '<div class="no-working-day"><i class="fas fa-bed"></i>ئەم ڕۆژە پشووە</div>';
            return;
        }

        if (!data.slots.length) {
            container.innerHTML = '<div class="no-working-day"><i class="fas fa-calendar-times"></i>هێچ کاتێکی بەردەست نییە</div>';
            return;
        }

        let html = '<div class="smart-time-picker-grid">';
        data.slots.forEach(slot => {
            const statusClass = slot.status === 'available' ? '' : slot.status;
            const clickHandler = (slot.status === 'available' || slot.status === 'booked') ? `onclick="selectSmartTimeSlot('${escapeHtml(slot.time)}')"` : '';
            const title = slot.info ? `title="${escapeHtml(slot.info)}"` : '';
            html += `<div class="smart-time-slot ${statusClass}" ${clickHandler} ${title}>${escapeHtml(slot.time)}</div>`;
        });
        html += '</div>';
        container.innerHTML = html;
    } catch (error) {
        console.error('Error loading slots:', error);
        container.innerHTML = '<div class="no-working-day"><i class="fas fa-exclamation-triangle"></i>هەڵە لە بارکردنی کاتەکان</div>';
    }
}

function timeToMinutes(timeStr) {
    if (!timeStr) return 0;
    const parts = timeStr.split(':');
    return parseInt(parts[0], 10) * 60 + parseInt(parts[1], 10);
}

function minutesToTime(minutes) {
    const hours = Math.floor(minutes / 60) % 24;
    const mins = minutes % 60;
    return `${hours.toString().padStart(2, '0')}:${mins.toString().padStart(2, '0')}`;
}

function checkAptOverlap(startTime, endTime) {
    const newStart = timeToMinutes(startTime);
    const newEnd = timeToMinutes(endTime);

    for (const booking of aptExistingBookings) {
        const bookingStart = timeToMinutes(booking.start_time);
        const bookingEnd = timeToMinutes(booking.end_time);
        if (newStart < bookingEnd && newEnd > bookingStart) {
            return booking;
        }
    }

    return null;
}

function updateAptBookingSummary(startTime, endTime, duration) {
    const bookingSummary = document.getElementById('aptBookingSummary');
    const dateInput = document.getElementById('appointmentDate');
    if (!bookingSummary) return;

    document.getElementById('aptSummaryDate').textContent = dateInput?.value || '-';
    document.getElementById('aptSummaryStartTime').textContent = startTime;
    document.getElementById('aptSummaryEndTime').textContent = endTime;

    let durationText = `${duration} خولەک`;
    if (duration >= 60) {
        const hours = Math.floor(duration / 60);
        const mins = duration % 60;
        durationText = mins > 0 ? `${hours} کاتژمێر و ${mins} خولەک` : `${hours} کاتژمێر`;
    }
    document.getElementById('aptSummaryDuration').textContent = durationText;

    const overlap = checkAptOverlap(startTime, endTime);
    if (overlap) {
        bookingSummary.classList.add('has-overlap');
        bookingSummary.querySelector('.summary-title').innerHTML = `<i class="fas fa-exclamation-triangle"></i> ئاگاداری: تێپەڕبوونی کات لەگەڵ ${escapeHtml(overlap.patient_name || 'یەکێکی تر')}`;
    } else {
        bookingSummary.classList.remove('has-overlap');
        bookingSummary.querySelector('.summary-title').innerHTML = '<i class="fas fa-info-circle"></i> پوختەی مەوعید';
    }

    bookingSummary.style.display = 'block';
}

function calculateAptEndTime() {
    const startTime = document.getElementById('appointmentTime').value;
    const durationSelect = document.getElementById('appointmentDuration');
    const endTimeInput = document.getElementById('appointmentEndTime');
    const bookingSummary = document.getElementById('aptBookingSummary');

    if (!startTime || !durationSelect) {
        if (bookingSummary) bookingSummary.style.display = 'none';
        return '';
    }

    const duration = parseInt(durationSelect.value, 10);
    const endTime = minutesToTime(timeToMinutes(startTime) + duration);
    if (endTimeInput) endTimeInput.value = endTime;
    updateAptBookingSummary(startTime, endTime, duration);
    return endTime;
}

function selectSmartTimeSlot(time) {
    document.getElementById('appointmentTime').value = time;
    document.querySelectorAll('#smartTimePickerContainer .smart-time-slot').forEach(slot => {
        slot.classList.toggle('selected', slot.textContent.trim() === time);
    });
    calculateAptEndTime();
}

document.addEventListener('DOMContentLoaded', function() {
    showTab(INITIAL_PATIENT_TAB);

    document.getElementById('appointmentDuration')?.addEventListener('change', function() {
        if (document.getElementById('appointmentTime').value) {
            calculateAptEndTime();
        }
        refreshPatientAppointmentTimePicker();
    });

    document.getElementById('patientAppointmentForm')?.addEventListener('submit', function(e) {
        const timeInput = document.getElementById('appointmentTime');
        if (!timeInput.value) {
            e.preventDefault();
            alert('تکایە کاتێک هەڵبژێرە');
            return false;
        }
        // Overlapping bookings are allowed by clinic policy — no overlap check
    });

    // ── Dialog manager: single dialog, scroll lock, backdrop close, autofocus ──
    const baseOpenDialog = window.openDialog;
    const baseCloseDialog = window.closeDialog;
    if (typeof baseOpenDialog === 'function') {
        window.openDialog = function(dialogId) {
            document.querySelectorAll('.dialog-overlay.open').forEach(d => {
                if (d.id !== dialogId) d.classList.remove('open');
            });
            baseOpenDialog(dialogId);
            document.body.style.overflow = 'hidden';
            setTimeout(() => {
                const dlg = document.getElementById(dialogId);
                const field = dlg?.querySelector('input:not([type=hidden]):not([readonly]):not([disabled]), select:not([disabled]), textarea:not([disabled])');
                if (field) field.focus();
            }, 80);
        };
        window.closeDialog = function(dialogId) {
            baseCloseDialog(dialogId);
            if (!document.querySelector('.dialog-overlay.open')) document.body.style.overflow = '';
        };
    }
    document.querySelectorAll('.dialog-overlay').forEach(overlay => {
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) closeDialog(overlay.id);
        });
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const openOverlay = document.querySelector('.dialog-overlay.open');
            if (openOverlay) closeDialog(openOverlay.id);
        }
    });

    // ── Double-submit guard + loading state for every POST form ──
    document.addEventListener('submit', function(e) {
        const form = e.target;
        if (!(form instanceof HTMLFormElement) || form.method.toLowerCase() !== 'post') return;
        if (e.defaultPrevented) return;               // a validator blocked it
        if (form.dataset.submitting === '1') { e.preventDefault(); return; }
        form.dataset.submitting = '1';
        setTimeout(() => {
            form.querySelectorAll('button[type=submit], input[type=submit]').forEach(btn => {
                btn.disabled = true;
                if (btn.tagName === 'BUTTON' && !btn.dataset.origHtml) {
                    btn.dataset.origHtml = btn.innerHTML;
                    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> چاوەڕوان بە...';
                }
            });
        }, 0);
    });
    window.addEventListener('pageshow', function(e) {
        if (e.persisted) {
            document.querySelectorAll('form[data-submitting]').forEach(form => {
                delete form.dataset.submitting;
                form.querySelectorAll('button[type=submit], input[type=submit]').forEach(btn => {
                    btn.disabled = false;
                    if (btn.dataset.origHtml) { btn.innerHTML = btn.dataset.origHtml; delete btn.dataset.origHtml; }
                });
            });
        }
    });

    // ── Failed POST: reopen the dialog pre-filled with what was typed ──
    if (PATIENT_FORM_RESTORE && PATIENT_FORM_RESTORE.dialog && document.getElementById(PATIENT_FORM_RESTORE.dialog)) {
        const dlg = document.getElementById(PATIENT_FORM_RESTORE.dialog);
        const values = PATIENT_FORM_RESTORE.values || {};
        Object.keys(values).forEach(name => {
            dlg.querySelectorAll('[name="' + CSS.escape(name) + '"]').forEach(field => {
                if (field.type === 'radio') field.checked = (field.value === values[name]);
                else if (field.type === 'checkbox') field.checked = !!values[name];
                else if (field.type !== 'file' && field.type !== 'hidden') field.value = values[name];
            });
        });
        setTimeout(() => {
            openDialog(PATIENT_FORM_RESTORE.dialog);
            if (PATIENT_FORM_RESTORE.dialog === 'newAppointmentDialog') {
                loadPatientDebt(PATIENT_APPOINTMENT.id);
                refreshPatientAppointmentTimePicker();
            }
        }, 250);
    }
});
</script>

<?php if ($isDoctor && !$isAdmin && isset($_GET['new_case'])): ?>
<!-- Opened from the waiting room "کەیس دانان" button: jump straight to the new-case dialog -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof openDialog === 'function' && document.getElementById('newCaseDialog')) {
        openDialog('newCaseDialog');
    }
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
