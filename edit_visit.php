<?php
/**
 * Edit Visit Page - Full Version
 * سیستەمی زیرەکی کلینیکی ددان
 */

// Include config first to access APP_URL
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/classes/VisitDoctorAssignment.php';

$pageTitle = 'دەستکاری سەردان';
$breadcrumb = [
    ['title' => 'سەردانەکان', 'url' => APP_URL . '/visits.php'],
    ['title' => 'دەستکاری سەردان']
];

require_once __DIR__ . '/includes/header.php';

// Allow the same operational roles that can manage visits from reception/waiting-room flows.
if (!$hasFullAccess && !$isDoctor) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

// Create mysqli connection for PatientManagerMysqli compatibility
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

// Sanitize function if not exists
if (!function_exists('sanitize')) {
    function sanitize($data) {
        return htmlspecialchars(trim($data ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

$success = '';
$error = '';
$errors = [];
$redirect_url = null;

// Get visit ID
$visit_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$visit_id) {
    header('Location: ' . APP_URL . '/visits.php');
    exit;
}

// Get visit data
$visitStmt = $conn->prepare("SELECT * FROM visits WHERE id = ?");
$visitStmt->bind_param("i", $visit_id);
$visitStmt->execute();
$visit = $visitStmt->get_result()->fetch_assoc();
$visitStmt->close();

if (!$visit) {
    header('Location: ' . APP_URL . '/visits.php?error=notfound');
    exit;
}

// Ownership: a doctor may only edit visits that belong to them.
// visits.*_doctor_id columns hold ids from the `doctors` table, so resolve
// the logged-in doctor's clinical record via doctors.user_id (name fallback
// covers legacy unlinked rows).
$editorDoctorId = 0;
if ($isDoctor && !$hasFullAccess) {
    $ownStmt = $conn->prepare(
        "SELECT id FROM doctors WHERE user_id = ?
         UNION
         SELECT d.id FROM doctors d JOIN users u ON u.full_name = d.name AND u.id = ?
         LIMIT 1"
    );
    $uid = (int)$currentUser['id'];
    $ownStmt->bind_param("ii", $uid, $uid);
    $ownStmt->execute();
    $ownRow = $ownStmt->get_result()->fetch_assoc();
    $ownStmt->close();
    $editorDoctorId = (int)($ownRow['id'] ?? 0);

    $visitBelongsToEditor = $editorDoctorId > 0 && (
        (int)$visit['requested_doctor_id'] === $editorDoctorId
        || (int)$visit['assigned_doctor_id'] === $editorDoctorId
    );
    if (!$visitBelongsToEditor) {
        header('Location: ' . APP_URL . '/403.php');
        exit;
    }
}

// Medical Conditions (same keys used in patients/add.php)
$MEDICAL_CONDITIONS = [
    'diabetes' => 'شەکرە',
    'blood_pressure' => 'پەستانی خوێن',
    'heart_disease' => 'نەخۆشی دڵ',
    'allergy' => 'حەساسیەت',
    'bleeding_disorder' => 'کێشەی خوێنڕۆیی',
    'pregnancy' => 'دووگیانی',
    'other' => 'هی تر'
];

class PatientManagerMysqli {
    private $conn;

    public function __construct($conn) {
        $this->conn = $conn;
    }

    private function generatePatientCode() {
        $year = date('Y');
        $prefix = "P{$year}%";

        $stmt = $this->conn->prepare("SELECT MAX(CAST(SUBSTRING(patient_code, 6) AS UNSIGNED)) as max_num FROM patients WHERE patient_code LIKE ?");
        if (!$stmt) {
            throw new Exception('Failed to prepare patient code query');
        }

        $stmt->bind_param("s", $prefix);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception('Failed to generate patient code');
        }

        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $nextNum = ((int)($row['max_num'] ?? 0)) + 1;
        return "P{$year}" . str_pad((string)$nextNum, 5, '0', STR_PAD_LEFT);
    }

    public function getById($id) {
        $stmt = $this->conn->prepare("SELECT * FROM patients WHERE id = ? LIMIT 1");
        if (!$stmt) {
            throw new Exception('Failed to prepare patient lookup');
        }
        $stmt->bind_param("i", $id);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception('Failed to fetch patient');
        }
        $patient = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $patient ?: null;
    }

    public function getMedicalConditions($patientId) {
        $stmt = $this->conn->prepare("SELECT condition_type, condition_details, severity FROM patient_medical_conditions WHERE patient_id = ?");
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param("i", $patientId);
        $stmt->execute();
        $result = $stmt->get_result();
        $conditions = [];
        while ($row = $result->fetch_assoc()) {
            $conditions[] = [
                'type' => $row['condition_type'],
                'details' => $row['condition_details'] ?? '',
                'severity' => $row['severity'] ?? 'medium'
            ];
        }
        $stmt->close();
        return $conditions;
    }

    public function findExistingIdByPhone($phone, $dateOfBirth = null, $fullName = null) {
        // Phone (+dob) alone is NOT identity: family members share a phone and
        // can share a birth date (see the Nza/Niga sister merge, 2026-08-01).
        // The stored full name must match the typed one too — a different name
        // on the same phone means a different person, so the caller must INSERT.
        // A wrongly-created duplicate can be merged later; a silent merge
        // overwrites one patient with another and poisons every linked record.
        $name = trim(preg_replace('/\s+/u', ' ', (string)$fullName));
        if ($name === '') {
            return null; // never match on phone alone
        }
        if (!empty($dateOfBirth)) {
            $stmt = $this->conn->prepare(
                "SELECT id FROM patients
                 WHERE phone = ? AND date_of_birth = ? AND deleted_at IS NULL
                   AND TRIM(full_name) = ? LIMIT 1");
            if (!$stmt) {
                throw new Exception('Failed to prepare patient lookup');
            }
            $stmt->bind_param("sss", $phone, $dateOfBirth, $name);
        } else {
            $stmt = $this->conn->prepare(
                "SELECT id FROM patients
                 WHERE phone = ? AND deleted_at IS NULL
                   AND TRIM(full_name) = ? LIMIT 1");
            if (!$stmt) {
                throw new Exception('Failed to prepare patient lookup');
            }
            $stmt->bind_param("ss", $phone, $name);
        }

        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception('Failed to find patient');
        }
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (int)$row['id'] : null;
    }

    public function create($data) {
        $patientCode = $this->generatePatientCode();

        $stmt = $this->conn->prepare(
            "INSERT INTO patients (
                patient_code, full_name, gender, date_of_birth, phone, phone2,
                address, city, occupation, blood_type, notes, profile_image,
                registered_by, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
        );

        if (!$stmt) {
            throw new Exception('Failed to prepare patient insert');
        }

        $fullName = $data['full_name'] ?? '';
        $gender = $data['gender'] ?? '';
        $dateOfBirth = $data['date_of_birth'] ?? null;
        $phone = $data['phone'] ?? '';
        $phone2 = $data['phone2'] ?? '';
        $address = $data['address'] ?? '';
        $city = $data['city'] ?? '';
        $occupation = $data['occupation'] ?? '';
        $bloodType = $data['blood_type'] ?? null;
        $notes = $data['notes'] ?? '';
        $profileImage = $data['profile_image'] ?? null;
        $registeredBy = (int)($data['registered_by'] ?? 0);

        $stmt->bind_param(
            "ssssssssssssi",
            $patientCode,
            $fullName,
            $gender,
            $dateOfBirth,
            $phone,
            $phone2,
            $address,
            $city,
            $occupation,
            $bloodType,
            $notes,
            $profileImage,
            $registeredBy
        );

        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception('Failed to insert patient');
        }
        $patientId = $stmt->insert_id;
        $stmt->close();

        if (!empty($data['medical_conditions']) && is_array($data['medical_conditions'])) {
            $this->replaceMedicalConditions($patientId, $data['medical_conditions']);
        }

        return ['success' => true, 'patient_id' => $patientId, 'patient_code' => $patientCode];
    }

    public function update($id, $data) {
        $stmt = $this->conn->prepare(
            "UPDATE patients SET
                full_name = ?, gender = ?, date_of_birth = ?, phone = ?, phone2 = ?,
                address = ?, city = ?, occupation = ?, blood_type = ?, notes = ?,
                profile_image = COALESCE(?, profile_image),
                updated_at = NOW()
             WHERE id = ?"
        );
        if (!$stmt) {
            throw new Exception('Failed to prepare patient update');
        }

        $fullName = $data['full_name'] ?? '';
        $gender = $data['gender'] ?? '';
        $dateOfBirth = $data['date_of_birth'] ?? null;
        $phone = $data['phone'] ?? '';
        $phone2 = $data['phone2'] ?? '';
        $address = $data['address'] ?? '';
        $city = $data['city'] ?? '';
        $occupation = $data['occupation'] ?? '';
        $bloodType = $data['blood_type'] ?? null;
        $notes = $data['notes'] ?? '';
        $profileImage = $data['profile_image'] ?? null;

        $stmt->bind_param(
            "sssssssssssi",
            $fullName,
            $gender,
            $dateOfBirth,
            $phone,
            $phone2,
            $address,
            $city,
            $occupation,
            $bloodType,
            $notes,
            $profileImage,
            $id
        );

        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception('Failed to update patient');
        }
        $stmt->close();

        if (array_key_exists('medical_conditions', $data) && is_array($data['medical_conditions'])) {
            $this->replaceMedicalConditions($id, $data['medical_conditions']);
        }

        return ['success' => true];
    }

    private function replaceMedicalConditions($patientId, $conditions) {
        // Delete existing conditions
        $deleteStmt = $this->conn->prepare("DELETE FROM patient_medical_conditions WHERE patient_id = ?");
        if (!$deleteStmt) {
            throw new Exception('Failed to prepare medical conditions delete');
        }
        $deleteStmt->bind_param("i", $patientId);
        if (!$deleteStmt->execute()) {
            $deleteStmt->close();
            throw new Exception('Failed to update medical conditions');
        }
        $deleteStmt->close();

        if (empty($conditions)) {
            return;
        }

        $insertStmt = $this->conn->prepare(
            "INSERT INTO patient_medical_conditions (patient_id, condition_type, condition_details, severity)
             VALUES (?, ?, ?, ?)"
        );
        if (!$insertStmt) {
            throw new Exception('Failed to prepare medical conditions insert');
        }

        foreach ($conditions as $condition) {
            $type = $condition['type'] ?? '';
            $details = $condition['details'] ?? null;
            $severity = $condition['severity'] ?? 'medium';
            $insertStmt->bind_param("isss", $patientId, $type, $details, $severity);
            if (!$insertStmt->execute()) {
                $insertStmt->close();
                throw new Exception('Failed to insert medical condition');
            }
        }

        $insertStmt->close();
    }
}

$patientManager = new PatientManagerMysqli($conn);

// پلانی چارەسەر written at this checkup. Once its doctor has decided who
// treats, the plan is the one place that decision lives: the دەستنیشانکەر /
// دەستنیشانکراو / هەڵبژێردراو / مەوعید fields below are filled from it
// (TreatmentPlan::syncVisit) and shown read-only here, so the visit and the
// plan can never disagree. Undecided plans leave the fields editable.
$linkedPlan = null;
try {
    require_once __DIR__ . '/classes/TreatmentPlan.php';
    $linkedPlan = (new TreatmentPlan())->planForVisit((int)$visit_id);
} catch (Throwable $e) {
    $linkedPlan = null;
}
$planDrivesRouting = $linkedPlan && !empty($linkedPlan['treating_mode']);
$routingLockAttr = $planDrivesRouting ? ' disabled title="لە پلانی چارەسەرەوە دێت"' : '';

// Get doctors for dropdown
$doctors = $conn->query("SELECT id, name FROM doctors WHERE is_active = 1 ORDER BY name");

// Reception employees only, PLUS whoever is already assigned to this visit —
// old visits may point at a non-receptionist and must keep displaying them
$receptionists = $conn->query("SELECT id, full_name FROM users
    WHERE (role IN ('receptionist', 'resident_receptionist') AND is_active = 1)
       OR id = (SELECT receptionist_id FROM visits WHERE id = {$visit_id})
    ORDER BY full_name");

// Get referral sources for dropdown
$referral_sources = $conn->query("SELECT name FROM referral_sources WHERE is_active = 1 ORDER BY name");

// Get procedures for dropdown
$procedures = $conn->query("SELECT name FROM procedures WHERE is_active = 1 ORDER BY name");

// Get work types for visit type controls — the type already saved on this
// visit is kept even if it was added on another page
$work_types_for_modal = $conn->query(
    "SELECT id, work_name FROM work_types
     WHERE is_active = 1 AND " . work_type_scope_sql('edit_visit', [$visit['work_type_id'] ?? 0]) . "
     ORDER BY sort_order, id"
);

// Cities list - grouped by province, same as new_visit.php
$cities_grouped = [];
if ($citiesRes = $conn->query("SELECT id, name, province FROM cities WHERE is_active = 1 ORDER BY sort_order, id")) {
    while ($cityRow = $citiesRes->fetch_assoc()) {
        $provinceKey = trim($cityRow['province'] ?? '');
        if ($provinceKey === '') {
            $provinceKey = 'ط´ط§ط±غ• طھط±';
        }
        $cities_grouped[$provinceKey][] = $cityRow['name'];
    }
}

// Load existing patient data if patient_id exists
$existingPatient = null;
$existingConditions = [];
if (!empty($visit['patient_id'])) {
    $existingPatient = $patientManager->getById($visit['patient_id']);
    if ($existingPatient) {
        $existingConditions = $patientManager->getMedicalConditions($visit['patient_id']);
    }
}

// Set form data from visit or patient
$formData = [];
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Pre-fill from existing data
    $formData = $visit;
    if ($existingPatient) {
        $formData['patient_id'] = $existingPatient['id'];
        $formData['full_name'] = $existingPatient['full_name'];
        $formData['gender'] = $existingPatient['gender'];
        $formData['date_of_birth'] = $existingPatient['date_of_birth'];
        $formData['phone'] = $existingPatient['phone'];
        $formData['phone2'] = $existingPatient['phone2'];
        $formData['address'] = $existingPatient['address'];
        $formData['city'] = $existingPatient['city'];
        $formData['occupation'] = $existingPatient['occupation'];
        $formData['blood_type'] = $existingPatient['blood_type'];
        $formData['patient_notes'] = $existingPatient['notes'];
        $formData['existing_profile_image'] = $existingPatient['profile_image'];

        // Build medical conditions arrays for form
        $formData['medical_conditions'] = [];
        $formData['condition_details'] = [];
        $formData['condition_severity'] = [];
        foreach ($existingConditions as $cond) {
            $formData['medical_conditions'][] = $cond['type'];
            $formData['condition_details'][$cond['type']] = $cond['details'];
            $formData['condition_severity'][$cond['type']] = $cond['severity'];
        }
    } else {
        // Use visit's patient_name and mobile_number as fallback
        $formData['full_name'] = $visit['patient_name'] ?? '';
        $formData['phone'] = $visit['mobile_number'] ?? '';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::require();

    // Patient (supports old fields for compatibility)
    $patient_id = !empty($_POST['patient_id']) ? (int)$_POST['patient_id'] : (!empty($visit['patient_id']) ? (int)$visit['patient_id'] : null);
    $patient_dirty = isset($_POST['patient_dirty']) ? (int)$_POST['patient_dirty'] : 0;
    $patient_conditions_loaded = isset($_POST['patient_conditions_loaded']) ? (int)$_POST['patient_conditions_loaded'] : 0;

    $full_name = sanitize($_POST['full_name'] ?? ($_POST['patient_name'] ?? ''));
    $phone = sanitize($_POST['phone'] ?? ($_POST['mobile_number'] ?? ''));
    $gender = sanitize($_POST['gender'] ?? '');
    $phone2 = sanitize($_POST['phone2'] ?? '');
    $date_of_birth = !empty($_POST['date_of_birth']) ? sanitize($_POST['date_of_birth']) : null;
    $address = sanitize($_POST['address'] ?? '');
    $city = sanitize($_POST['city'] ?? '');
    $occupation = sanitize($_POST['occupation'] ?? '');
    $blood_type = !empty($_POST['blood_type']) ? sanitize($_POST['blood_type']) : null;
    $patient_notes = sanitize($_POST['patient_notes'] ?? '');

    // Medical conditions (same structure as patients/add.php)
    $medical_conditions = [];
    if (!empty($_POST['medical_conditions']) && is_array($_POST['medical_conditions'])) {
        foreach ($_POST['medical_conditions'] as $condKey) {
            $type = sanitize($condKey);
            $details = isset($_POST['condition_details'][$condKey]) ? sanitize($_POST['condition_details'][$condKey]) : '';
            $severity = isset($_POST['condition_severity'][$condKey]) ? sanitize($_POST['condition_severity'][$condKey]) : 'medium';
            $medical_conditions[] = [
                'type' => $type,
                'details' => $details,
                'severity' => $severity
            ];
        }
    }

    // Keep compatibility values for visits table
    $patient_name = $full_name;
    $mobile_number = $phone;

    // Visit fields
    $treated = isset($_POST['treated']) ? sanitize($_POST['treated']) : 'no';
    $treated_reason = !empty($_POST['treated_reason']) ? sanitize($_POST['treated_reason']) : null;
    $requested_doctor_id = !empty($_POST['requested_doctor_id']) ? (int)$_POST['requested_doctor_id'] : null;
    $assigned_doctor_id = !empty($_POST['assigned_doctor_id']) ? (int)$_POST['assigned_doctor_id'] : null;
    $assigned_doctor_reason = !empty($_POST['assigned_doctor_reason']) ? sanitize($_POST['assigned_doctor_reason']) : null;
    $arrival_time = sanitize($_POST['arrival_time'] ?? '');
    $checkin_time = !empty($_POST['checkin_time']) ? sanitize($_POST['checkin_time']) : null;
    $notification_time = !empty($_POST['notification_time']) ? sanitize($_POST['notification_time']) : null;
    $procedure_treatment = sanitize($_POST['procedure_treatment'] ?? '');
    $work_type_id = !empty($_POST['work_type_id']) ? (int)$_POST['work_type_id'] : null;
    $visit_type = sanitize($_POST['visit_type'] ?? '');
    if (!in_array($visit_type, ['checkup', 'treatment'], true)) {
        $visit_type = '';
    }
    $visit_price_raw = str_replace(',', '', trim((string)($_POST['price'] ?? '0')));
    $visit_price = is_numeric($visit_price_raw) ? (float)$visit_price_raw : 0.0;
    if ($visit_price < 0) {
        $errors['price'] = 'Price cannot be negative';
    }
    if ($visit_type === 'treatment') {
        $visit_price = 0.0;
    }
    $patient_status = sanitize($_POST['patient_status'] ?? '');
    // Attribution fields must not silently move to whoever edits the visit:
    // keep the stored values; only admin/receptionist may change them explicitly.
    if ($hasFullAccess && !empty($_POST['receptionist_id'])) {
        $receptionist_id = (int)$_POST['receptionist_id'];
    } else {
        $receptionist_id = $visit['receptionist_id'] ?: $currentUser['id'];
    }
    $referral_source = sanitize($_POST['referral_source'] ?? '');
    $referral_source_notes = !empty($_POST['referral_source_notes']) ? sanitize($_POST['referral_source_notes']) : null;
    $appointment_date = !empty($_POST['appointment_date']) ? sanitize($_POST['appointment_date']) : null;
    if ($hasFullAccess && !empty($_POST['appointment_added_by'])) {
        $appointment_added_by = (int)$_POST['appointment_added_by'];
    } else {
        $appointment_added_by = $visit['appointment_added_by'] ?: $currentUser['id'];
    }
    // Preserve the stored appointment doctor instead of wiping it on every save
    $appointment_doctor_id = !empty($visit['appointment_doctor_id']) ? (int)$visit['appointment_doctor_id'] : null;
    $referring_doctor_id = !empty($_POST['referring_doctor_id']) ? (int)$_POST['referring_doctor_id'] : null;
    $referring_doctor_designation = !empty($_POST['referring_doctor_designation']) ? sanitize($_POST['referring_doctor_designation']) : null;
    $referring_doctor_choice_id = !empty($_POST['referring_doctor_choice_id']) ? (int)$_POST['referring_doctor_choice_id'] : null;
    if ($planDrivesRouting) {
        // The plan owns these (fields are disabled, so nothing was posted):
        // keep exactly what the plan wrote.
        $appointment_date = $visit['appointment_date'] ?: null;
        $appointment_added_by = !empty($visit['appointment_added_by']) ? (int)$visit['appointment_added_by'] : null;
        $referring_doctor_id = ($visit['referring_doctor_id'] ?? '') !== '' ? (int)$visit['referring_doctor_id'] : null;
        $referring_doctor_designation = ($visit['referring_doctor_designation'] ?? '') !== '' ? $visit['referring_doctor_designation'] : null;
        $referring_doctor_choice_id = !empty($visit['referring_doctor_choice_id']) ? (int)$visit['referring_doctor_choice_id'] : null;
    }
    if ($visit_type === 'checkup' && $referring_doctor_designation === 'PATIENT' && (!$referring_doctor_id || !$referring_doctor_choice_id)) {
        $errors['patient_referral'] = 'بۆ هەڵبژاردنی نەخۆش، دکتۆری پشکنەر و دکتۆری هەڵبژێردراو دیاری بکە';
    }
    $notes = sanitize($_POST['notes'] ?? '');
    $visit_date = !empty($_POST['visit_date']) ? sanitize($_POST['visit_date']) : date('Y-m-d');

    // Patient validations
    if (empty($full_name)) {
        $errors['full_name'] = 'ناو پێویستە';
    }
    if (empty($phone)) {
        $errors['phone'] = 'ژمارەی مۆبایل پێویستە';
    }
    if (empty($gender)) {
        $errors['gender'] = 'ڕەگەز پێویستە';
    }

    // Required visit fields
    $needsCheckupFields = $visit_type === 'checkup';
    $doctor_assignment_source = $needsCheckupFields ? VisitDoctorAssignment::derive($requested_doctor_id, $assigned_doctor_id) : null;
    if (($needsCheckupFields && empty($arrival_time)) || empty($patient_status) || empty($visit_type)) {
        $errors['visit_required'] = 'تکایە خانە پێویستەکانی سەردان پڕ بکەوە';
    }
    if (empty($visit_type)) {
        $errors['visit_type'] = 'ط¬غ†ط±غŒ ظ†غ•ط®غ†ط´ ظ¾غژظˆغŒط³طھغ• ظ‡غ•عµط¨عکغژط±ط¯ط±غژطھ';
    }

    if (empty($errors)) {
        $checkWorkTypeColumn = $conn->query("SHOW COLUMNS FROM visits LIKE 'work_type_id'");
        if ($checkWorkTypeColumn && $checkWorkTypeColumn->num_rows == 0) {
            $conn->query("ALTER TABLE visits ADD COLUMN work_type_id INT NULL AFTER procedure_treatment");
        }

        $checkVisitTypeColumn = $conn->query("SHOW COLUMNS FROM visits LIKE 'visit_type'");
        if ($checkVisitTypeColumn && $checkVisitTypeColumn->num_rows == 0) {
            $conn->query("ALTER TABLE visits ADD COLUMN visit_type ENUM('checkup','treatment') NULL DEFAULT 'checkup' AFTER work_type_id");
        }

        $checkPriceColumn = $conn->query("SHOW COLUMNS FROM visits LIKE 'price'");
        if ($checkPriceColumn && $checkPriceColumn->num_rows == 0) {
            $conn->query("ALTER TABLE visits ADD COLUMN price DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER procedure_treatment");
            $conn->query("ALTER TABLE visits ADD INDEX idx_visits_price (price)");
        }

        $checkWaitingWorkTypeColumn = $conn->query("SHOW COLUMNS FROM waiting_room LIKE 'work_type_id'");
        if ($checkWaitingWorkTypeColumn && $checkWaitingWorkTypeColumn->num_rows == 0) {
            $conn->query("ALTER TABLE waiting_room ADD COLUMN work_type_id INT NULL AFTER appointment_id");
        }

        $checkWaitingVisitTypeColumn = $conn->query("SHOW COLUMNS FROM waiting_room LIKE 'visit_type'");
        if ($checkWaitingVisitTypeColumn && $checkWaitingVisitTypeColumn->num_rows == 0) {
            $conn->query("ALTER TABLE waiting_room ADD COLUMN visit_type ENUM('checkup','treatment') NOT NULL DEFAULT 'treatment' AFTER doctor_id");
        }
    }

    // Function to compress image
    function compressImage($source, $destination, $quality = 75) {
        $info = getimagesize($source);
        $mime = $info['mime'];

        switch ($mime) {
            case 'image/jpeg':
                $image = imagecreatefromjpeg($source);
                break;
            case 'image/png':
                $image = imagecreatefrompng($source);
                break;
            case 'image/gif':
                $image = imagecreatefromgif($source);
                break;
            default:
                return false;
        }

        $width = imagesx($image);
        $height = imagesy($image);

        $max_dimension = 1200;
        if ($width > $max_dimension || $height > $max_dimension) {
            if ($width > $height) {
                $new_width = $max_dimension;
                $new_height = floor($height * ($max_dimension / $width));
            } else {
                $new_height = $max_dimension;
                $new_width = floor($width * ($max_dimension / $height));
            }
        } else {
            $new_width = $width;
            $new_height = $height;
        }

        $new_image = imagecreatetruecolor($new_width, $new_height);

        if ($mime == 'image/png' || $mime == 'image/gif') {
            imagealphablending($new_image, false);
            imagesavealpha($new_image, true);
            $transparent = imagecolorallocatealpha($new_image, 255, 255, 255, 127);
            imagefilledrectangle($new_image, 0, 0, $new_width, $new_height, $transparent);
        }

        imagecopyresampled($new_image, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);

        $saved = false;
        switch ($mime) {
            case 'image/jpeg':
                $saved = imagejpeg($new_image, $destination, $quality);
                break;
            case 'image/png':
                $png_quality = floor((100 - $quality) / 11);
                $saved = imagepng($new_image, $destination, $png_quality);
                break;
            case 'image/gif':
                $saved = imagegif($new_image, $destination);
                break;
        }

        imagedestroy($image);
        imagedestroy($new_image);

        return $saved;
    }

    $uploaded_files = [];

    // Handle patient profile image upload
    $profile_image = null;
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = __DIR__ . '/uploads/patients/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        $file_extension = strtolower(pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION));
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];

        if (in_array($file_extension, $allowed_extensions)) {
            $new_filename = uniqid('profile_') . '.' . $file_extension;
            $upload_path = $upload_dir . $new_filename;
            $temp_path = $_FILES['profile_image']['tmp_name'];

            $quality = 80;
            $compressed = false;
            while ($quality >= 40 && !$compressed) {
                if (compressImage($temp_path, $upload_path, $quality)) {
                    $compressed = true;
                    $profile_image = 'uploads/patients/' . $new_filename;
                    $uploaded_files[] = $upload_path;
                } else {
                    break;
                }
                $quality -= 10;
            }

            if (!$compressed && move_uploaded_file($temp_path, $upload_path)) {
                $profile_image = 'uploads/patients/' . $new_filename;
                $uploaded_files[] = $upload_path;
            }
        }
    }

    // Handle appointment image upload
    $appointment_image = $visit['appointment_image'] ?? null; // Keep existing if not changed
    if (isset($_FILES['appointment_image']) && $_FILES['appointment_image']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = __DIR__ . '/uploads/appointments/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        $file_extension = strtolower(pathinfo($_FILES['appointment_image']['name'], PATHINFO_EXTENSION));
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];

        if (in_array($file_extension, $allowed_extensions)) {
            $new_filename = uniqid('appointment_') . '.' . $file_extension;
            $upload_path = $upload_dir . $new_filename;

            if ($file_extension === 'pdf') {
                if (move_uploaded_file($_FILES['appointment_image']['tmp_name'], $upload_path)) {
                    $appointment_image = 'uploads/appointments/' . $new_filename;
                }
            } else {
                $temp_path = $_FILES['appointment_image']['tmp_name'];
                $quality = 75;
                $compressed = false;

                while ($quality >= 40 && !$compressed) {
                    if (compressImage($temp_path, $upload_path, $quality)) {
                        $file_size = filesize($upload_path);
                        if ($file_size <= 150000) {
                            $compressed = true;
                            $appointment_image = 'uploads/appointments/' . $new_filename;
                        } elseif ($quality > 40) {
                            $quality -= 10;
                        } else {
                            $compressed = true;
                            $appointment_image = 'uploads/appointments/' . $new_filename;
                        }
                    } else {
                        break;
                    }
                }

                if (!$compressed && move_uploaded_file($temp_path, $upload_path)) {
                    $appointment_image = 'uploads/appointments/' . $new_filename;
                }
            }
        }
    }

    if (!empty($appointment_image) && $appointment_image !== ($visit['appointment_image'] ?? null)) {
        $uploaded_files[] = __DIR__ . '/' . $appointment_image;
    }

    if (empty($errors)) {
        try {
            $conn->begin_transaction();

            // Update/create patient
            $final_patient_id = $patient_id;

            if (!empty($patient_id)) {
                $existingPatient = $patientManager->getById($patient_id);
                if ($existingPatient) {
                    $shouldUpdatePatient = ($patient_dirty === 1) || ($profile_image !== null);
                    if (!$shouldUpdatePatient) {
                        $shouldUpdatePatient =
                            ($existingPatient['full_name'] ?? '') !== $full_name ||
                            ($existingPatient['phone'] ?? '') !== $phone ||
                            ($existingPatient['gender'] ?? '') !== $gender ||
                            ($existingPatient['date_of_birth'] ?? null) !== $date_of_birth ||
                            ($existingPatient['phone2'] ?? '') !== $phone2 ||
                            ($existingPatient['address'] ?? '') !== $address ||
                            ($existingPatient['city'] ?? '') !== $city ||
                            ($existingPatient['occupation'] ?? '') !== $occupation ||
                            ($existingPatient['blood_type'] ?? null) !== $blood_type ||
                            ($existingPatient['notes'] ?? '') !== $patient_notes;
                    }

                    if ($shouldUpdatePatient) {
                        $updateData = [
                            'full_name' => $full_name,
                            'gender' => $gender,
                            'date_of_birth' => $date_of_birth,
                            'phone' => $phone,
                            'phone2' => $phone2,
                            'address' => $address,
                            'city' => $city,
                            'occupation' => $occupation,
                            'blood_type' => $blood_type,
                            'notes' => $patient_notes,
                            'profile_image' => $profile_image
                        ];

                        if ($patient_conditions_loaded === 1) {
                            $updateData['medical_conditions'] = $medical_conditions;
                        }

                        $patientManager->update($patient_id, $updateData);
                    }
                }
            } else {
                // Try to find existing patient by phone + name (name must match — see method)
                $existingId = $patientManager->findExistingIdByPhone($phone, $date_of_birth, $full_name);
                if (!empty($existingId)) {
                    $final_patient_id = $existingId;
                    if ($profile_image !== null) {
                        $existingPatient = $patientManager->getById($existingId);
                        if ($existingPatient) {
                            $patientManager->update($existingId, [
                                'full_name' => $existingPatient['full_name'] ?? $full_name,
                                'gender' => $existingPatient['gender'] ?? $gender,
                                'date_of_birth' => $existingPatient['date_of_birth'] ?? $date_of_birth,
                                'phone' => $existingPatient['phone'] ?? $phone,
                                'phone2' => $existingPatient['phone2'] ?? $phone2,
                                'address' => $existingPatient['address'] ?? $address,
                                'city' => $existingPatient['city'] ?? $city,
                                'occupation' => $existingPatient['occupation'] ?? $occupation,
                                'blood_type' => $existingPatient['blood_type'] ?? $blood_type,
                                'notes' => $existingPatient['notes'] ?? $patient_notes,
                                'profile_image' => $profile_image
                            ]);
                        }
                    }
                } else {
                    // Create new patient
                    $createResult = $patientManager->create([
                        'full_name' => $full_name,
                        'gender' => $gender,
                        'phone' => $phone,
                        'phone2' => $phone2,
                        'date_of_birth' => $date_of_birth,
                        'address' => $address,
                        'city' => $city,
                        'occupation' => $occupation,
                        'blood_type' => $blood_type,
                        'notes' => $patient_notes,
                        'profile_image' => $profile_image,
                        'registered_by' => $currentUser['id'],
                        'medical_conditions' => $medical_conditions
                    ]);
                    $final_patient_id = (int)$createResult['patient_id'];
                }
            }

            // Get patient name/phone for visit record
            if ($final_patient_id) {
                $patientRow = $patientManager->getById($final_patient_id);
                if ($patientRow) {
                    $patient_name = $patientRow['full_name'];
                    $mobile_number = $patientRow['phone'];
                }
            }

            // Update visit (updated_by records the acting user)
            $updated_by = (int)$currentUser['id'];
            $updateStmt = $conn->prepare("UPDATE visits SET
                patient_id = ?, patient_name = ?, mobile_number = ?, treated = ?, treated_reason = ?,
                requested_doctor_id = ?, assigned_doctor_id = ?, assigned_doctor_reason = ?,
                arrival_time = ?, checkin_time = ?, notification_time = ?, procedure_treatment = ?,
                work_type_id = ?, visit_type = ?, price = ?, patient_status = ?, receptionist_id = ?, referral_source = ?, referral_source_notes = ?,
                appointment_date = ?, appointment_added_by = ?, appointment_doctor_id = ?,
                referring_doctor_id = ?, referring_doctor_designation = ?, referring_doctor_choice_id = ?,
                appointment_image = ?, notes = ?, visit_date = ?, updated_by = ?
                WHERE id = ?");

            if (!$updateStmt) {
                throw new Exception('Failed to prepare visit update: ' . $conn->error);
            }

            $updateStmt->bind_param("issssiisssssisdsisssiiisisssii",
                $final_patient_id, $patient_name, $mobile_number, $treated, $treated_reason,
                $requested_doctor_id, $assigned_doctor_id, $assigned_doctor_reason,
                $arrival_time, $checkin_time, $notification_time, $procedure_treatment,
                $work_type_id, $visit_type, $visit_price, $patient_status, $receptionist_id, $referral_source, $referral_source_notes,
                $appointment_date, $appointment_added_by, $appointment_doctor_id,
                $referring_doctor_id, $referring_doctor_designation, $referring_doctor_choice_id,
                $appointment_image, $notes, $visit_date, $updated_by, $visit_id
            );

            if (!$updateStmt->execute()) {
                $updateError = $updateStmt->error;
                $updateStmt->close();
                throw new Exception('Failed to update visit: ' . $updateError);
            }
            $updateStmt->close();

            VisitDoctorAssignment::saveSource($conn, (int) $visit_id, $doctor_assignment_source);

            // Audit: record only the fields that actually changed
            AuditLog::logChange('update_visit', 'visits', $visit_id, $visit, [
                'patient_id' => $final_patient_id,
                'patient_name' => $patient_name,
                'mobile_number' => $mobile_number,
                'treated' => $treated,
                'treated_reason' => $treated_reason,
                'requested_doctor_id' => $requested_doctor_id,
                'assigned_doctor_id' => $assigned_doctor_id,
                'doctor_assignment_source' => $doctor_assignment_source,
                'arrival_time' => $arrival_time,
                'checkin_time' => $checkin_time,
                'procedure_treatment' => $procedure_treatment,
                'work_type_id' => $work_type_id,
                'visit_type' => $visit_type,
                'price' => $visit_price,
                'patient_status' => $patient_status,
                'receptionist_id' => $receptionist_id,
                'referral_source' => $referral_source,
                'appointment_date' => $appointment_date,
                'appointment_added_by' => $appointment_added_by,
                'notes' => $notes,
                'visit_date' => $visit_date,
            ]);

            $send_to_waiting = !empty($_POST['send_to_waiting']);
            if ($send_to_waiting) {
                $doctor_user_id = null;
                if (!empty($assigned_doctor_id)) {
                    // Prefer the explicit doctors.user_id link; fall back to
                    // the legacy name match for doctors not linked yet.
                    $userLookupStmt = $conn->prepare(
                        "SELECT u.id FROM doctors d
                         JOIN users u ON u.id = d.user_id
                         WHERE d.id = ? AND u.role = 'doctor' AND u.is_active = 1
                         UNION
                         SELECT u.id FROM users u
                         JOIN doctors d ON d.name = u.full_name
                         WHERE d.id = ? AND u.role = 'doctor' AND u.is_active = 1
                         LIMIT 1"
                    );
                    if ($userLookupStmt) {
                        $userLookupStmt->bind_param('ii', $assigned_doctor_id, $assigned_doctor_id);
                        if ($userLookupStmt->execute()) {
                            $userLookupRes = $userLookupStmt->get_result();
                            if ($userLookupRow = $userLookupRes->fetch_assoc()) {
                                $doctor_user_id = (int)$userLookupRow['id'];
                            }
                        }
                        $userLookupStmt->close();
                    }
                }

                $finalDoctorId = $doctor_user_id ?: ($currentUser['id'] ?? null);
                if ($finalDoctorId) {
                    $alreadyWaiting = false;
                    if ($dupStmt = $conn->prepare(
                        "SELECT id FROM waiting_room
                         WHERE patient_id = ? AND status IN ('waiting','in_progress')
                         LIMIT 1"
                    )) {
                        $dupStmt->bind_param('i', $final_patient_id);
                        $dupStmt->execute();
                        $alreadyWaiting = (bool)$dupStmt->get_result()->fetch_assoc();
                        $dupStmt->close();
                    }

                    if (!$alreadyWaiting) {
                        $wrCheckIn = date('Y-m-d H:i:s');
                        $wrStatus = 'waiting';
                        $wrPriority = 0;
                        $wrNotes = '';
                        $wrCreatedBy = (int)$currentUser['id'];
                        $wrInsert = $conn->prepare(
                            "INSERT INTO waiting_room
                                (patient_id, doctor_id, work_type_id, visit_type, check_in_time, status, priority, notes, created_by)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                        );
                        if ($wrInsert) {
                            $wrInsert->bind_param(
                                'iiisssisi',
                                $final_patient_id,
                                $finalDoctorId,
                                $work_type_id,
                                $visit_type,
                                $wrCheckIn,
                                $wrStatus,
                                $wrPriority,
                                $wrNotes,
                                $wrCreatedBy
                            );
                            if (!$wrInsert->execute()) {
                                $waitingError = $wrInsert->error;
                                $wrInsert->close();
                                throw new Exception('Failed to add waiting-room entry: ' . $waitingError);
                            }
                            $wrWaitingId = $conn->insert_id;
                            $wrInsert->close();
                            AuditLog::log('waiting_check_in', 'waiting_room', $wrWaitingId, null, [
                                'patient_id' => $final_patient_id,
                                'doctor_id'  => $finalDoctorId,
                                'source'     => 'edit_visit',
                            ]);

                            // Notify the assigned doctor (fail-soft)
                            try {
                                require_once __DIR__ . '/classes/Notification.php';
                                $wrPatient = Database::getInstance()->fetch(
                                    "SELECT full_name FROM patients WHERE id = :id",
                                    ['id' => (int)$final_patient_id]
                                );
                                (new Notification())->notifyPatientWaiting(
                                    (int)$finalDoctorId,
                                    $wrPatient['full_name'] ?? '',
                                    $wrWaitingId,
                                    (int)$currentUser['id']
                                );
                            } catch (Exception $notifyError) {
                                error_log('edit_visit notify failed: ' . $notifyError->getMessage());
                            }
                        }
                    }
                }
            }

            $conn->commit();

            // Keep دکتۆری فەحس / دکتۆری ئیش in sync with the edited visit
            // (after commit — the PDO write must not wait on the mysqli tx lock).
            require_once __DIR__ . '/classes/VisitDoctorSync.php';
            VisitDoctorSync::assignToVisit(
                Database::getInstance(),
                (int)$visit_id,
                (int)$final_patient_id,
                $visit_type,
                (int)($assigned_doctor_id ?: $requested_doctor_id ?: 0)
            );

            $redirect_url = APP_URL . ($send_to_waiting ? "/waiting-room.php?updated=1" : "/visits.php?updated=1");
        } catch (Exception $e) {
            $conn->rollback();

            foreach ($uploaded_files as $path) {
                if (!empty($path) && file_exists($path)) {
                    @unlink($path);
                }
            }

            $error = 'هەڵە ڕوویدا لە نوێکردنەوەی نەخۆش و سەردان';
        }
    } else {
        $error = 'تکایە خانە پێویستەکان پڕبکەرەوە';
        $formData = $_POST;
    }
}

// Use formData for display
$displayData = !empty($formData) ? $formData : $_POST;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST'
    && ($displayData['visit_type'] ?? '') === 'checkup'
    && empty($displayData['assigned_doctor_id']) && !empty($displayData['checkup_doctor_id'])) {
    $displayData['assigned_doctor_id'] = $displayData['checkup_doctor_id'];
}
if ($planDrivesRouting) {
    // A re-rendered form (validation error) must still show the plan's values.
    foreach (['appointment_date', 'appointment_added_by', 'referring_doctor_id',
              'referring_doctor_designation', 'referring_doctor_choice_id'] as $routingField) {
        $displayData[$routingField] = $visit[$routingField] ?? null;
    }
}

// If validation errors exist, show a consistent message
if (!empty($errors)) {
    $error = $errors['visit_required'] ?? 'تکایە خانە پێویستەکان پڕ بکەوە';
}
?>

<?php if ($redirect_url): ?>
<script>window.location.href = '<?= $redirect_url ?>';</script>
<?php endif; ?>

<style>
/* Compact Design - matches new_visit.php */
.report-container {
    max-width: 1400px;
    margin: 0 auto;
}

.report-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
    flex-wrap: wrap;
    gap: 12px;
}

.report-title {
    display: flex;
    align-items: center;
    gap: 10px;
}

.report-title h2 {
    margin: 0;
    font-size: 18px;
    color: #1a1a1a;
}

.report-title .icon {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, #4caf50, #388e3c);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 18px;
    box-shadow: 0 3px 10px rgba(76, 175, 80, 0.3);
}

/* Edit Mode Indicator */
.edit-mode-badge {
    background: linear-gradient(135deg, #fff3e0, #ffe0b2);
    color: #e65100;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
    border: 1.5px solid #ffcc80;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

/* Compact Form Sections */
.form-card {
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    margin-bottom: 12px;
    overflow: visible;
}

.form-card-header {
    padding: 12px 16px;
    border-bottom: 1px solid #f0f0f0;
    display: flex;
    align-items: center;
    gap: 10px;
    background: linear-gradient(135deg, #fafafa, #f5f5f5);
    border-radius: 10px 10px 0 0;
}

.form-card-header .card-icon {
    width: 30px;
    height: 30px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    color: white;
}

.form-card-header h3 {
    margin: 0;
    font-size: 14px;
    font-weight: 700;
    color: #333;
}

.form-card-body {
    padding: 14px 16px;
}

/* Icon Colors */
.icon-blue { background: linear-gradient(135deg, var(--win-accent, #0078d4), var(--win-accent-deep, #0063b1)); }
.icon-green { background: linear-gradient(135deg, #4caf50, #388e3c); }
.icon-purple { background: linear-gradient(135deg, #9c27b0, #7b1fa2); }
.icon-orange { background: linear-gradient(135deg, #ff9800, #f57c00); }
.icon-teal { background: linear-gradient(135deg, #00bcd4, #0097a7); }
.icon-pink { background: linear-gradient(135deg, #e91e63, #c2185b); }
.icon-indigo { background: linear-gradient(135deg, #3f51b5, #303f9f); }
.icon-amber { background: linear-gradient(135deg, #ffc107, #ff8f00); }

/* Compact Form Grid */
.form-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
}

.form-grid-3 {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
}

.form-grid-2 {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
}

@media (max-width: 1100px) {
    .form-grid { grid-template-columns: repeat(3, 1fr); }
}

@media (max-width: 800px) {
    .form-grid, .form-grid-3 { grid-template-columns: repeat(2, 1fr); }
}

@media (max-width: 500px) {
    .form-grid, .form-grid-3, .form-grid-2 { grid-template-columns: 1fr; }
}

.form-group {
    margin-bottom: 0;
}

.form-group.span-2 { grid-column: span 2; }
.form-group.span-3 { grid-column: span 3; }
.form-group.full-width { grid-column: 1 / -1; }
.form-group:has(input[name="patient_status_checkup_view"]) { display: none; }

/* Compact Labels */
.form-group label {
    display: block;
    font-size: 11px;
    color: #555;
    font-weight: 600;
    margin-bottom: 4px;
}

.form-group label .required {
    color: #f44336;
    margin-right: 2px;
}

/* Compact Inputs */
.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 8px 10px;
    border: 1.5px solid #e0e0e0;
    border-radius: 6px;
    font-size: 13px;
    font-family: inherit;
    transition: all 0.2s;
    background: #fff;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    border-color: #ff9800;
    outline: none;
    box-shadow: 0 0 0 3px rgba(255, 152, 0, 0.1);
}

.form-group input.error,
.form-group select.error {
    border-color: #f44336;
}

textarea {
    resize: vertical;
    min-height: 50px;
}

.form-hint {
    font-size: 10px;
    color: #888;
    margin-top: 2px;
}

/* Search Section */
.search-card {
    background: white;
    border-radius: 10px;
    padding: 12px 16px;
    margin-bottom: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    border-right: 3px solid #ff9800;
}

.search-row {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
}

.search-input-wrapper {
    flex: 1;
    min-width: 250px;
    position: relative;
}

.search-input-wrapper input {
    width: 100%;
    padding: 10px 14px;
    border: 1.5px solid #e0e0e0;
    border-radius: 8px;
    font-size: 13px;
}

.search-input-wrapper input:focus {
    border-color: #ff9800;
    outline: none;
}

.search-results {
    position: absolute;
    top: calc(100% + 4px);
    right: 0;
    left: 0;
    background: white;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    box-shadow: 0 6px 20px rgba(0,0,0,0.12);
    max-height: 220px;
    overflow-y: auto;
    z-index: 1000;
    display: none;
}

.search-item {
    padding: 10px 14px;
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    gap: 10px;
    border-bottom: 1px solid #f0f0f0;
    font-size: 13px;
}

.search-item:last-child { border-bottom: none; }
.search-item:hover { background: #fff3e0; }
.search-item .name { font-weight: 600; color: #333; }
.search-item .phone { color: #888; }

.btn-search {
    padding: 10px 16px;
    background: linear-gradient(135deg, #ff9800, #f57c00);
    color: white;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-size: 13px;
    font-family: inherit;
    display: flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
}

.btn-search:hover {
    background: linear-gradient(135deg, #f57c00, #ef6c00);
}

.btn-clear {
    padding: 10px 16px;
    background: #f5f5f5;
    color: #666;
    border: 1.5px solid #e0e0e0;
    border-radius: 8px;
    cursor: pointer;
    font-size: 13px;
    font-family: inherit;
    display: flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
}

.btn-clear:hover {
    background: #e0e0e0;
}

/* Compact Radio Group */
.radio-group {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.radio-label {
    display: flex;
    align-items: center;
    gap: 5px;
    cursor: pointer;
    padding: 6px 12px;
    border: 1.5px solid #e0e0e0;
    border-radius: 6px;
    transition: all 0.2s;
    font-weight: 500;
    font-size: 12px;
}

.radio-label:hover {
    border-color: #ff9800;
    background: #fff8e1;
}

.radio-label input[type="radio"] {
    width: 14px;
    height: 14px;
    accent-color: #ff9800;
}

.radio-label input[type="radio"]:checked + span {
    color: #ff9800;
    font-weight: 600;
}

/* Compact Medical Conditions */
.conditions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: 8px;
}

.condition-card {
    background: #fafafa;
    border: 1.5px solid #e0e0e0;
    border-radius: 6px;
    padding: 8px 10px;
    cursor: pointer;
    transition: all 0.2s;
}

.condition-card:hover {
    border-color: #e91e63;
    background: #fce4ec;
}

.condition-card.active {
    border-color: #e91e63;
    background: #fce4ec;
}

.condition-header {
    display: flex;
    align-items: center;
    gap: 6px;
}

.condition-header input {
    width: 14px;
    height: 14px;
    accent-color: #e91e63;
}

.condition-header label {
    font-weight: 600;
    font-size: 11px;
    cursor: pointer;
    margin-bottom: 0 !important;
}

.condition-details {
    margin-top: 8px;
    display: none;
}

.condition-card.active .condition-details {
    display: block;
}

.condition-details input,
.condition-details select {
    width: 100%;
    padding: 5px 8px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 11px;
    margin-bottom: 4px;
}

/* Form Actions - Amber Theme */
.form-actions {
    display: flex;
    gap: 12px;
    justify-content: center;
    padding: 16px;
    background: #f8f9fa;
    border-radius: 10px;
    margin-top: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    border: 1px solid #e9ecef;
}

.form-actions .btn {
    padding: 10px 24px;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s;
    text-decoration: none;
}

.form-actions .btn-primary {
    background: linear-gradient(135deg, #4caf50, #388e3c);
    color: white;
    box-shadow: 0 3px 10px rgba(76, 175, 80, 0.3);
}

.form-actions .btn-primary:hover {
    background: linear-gradient(135deg, #388e3c, #2e7d32);
}

.form-actions .btn-save-send {
    background: linear-gradient(135deg, #16a34a, #15803d);
    box-shadow: 0 3px 10px rgba(22, 163, 74, 0.25);
}

.form-actions .btn-save-send:hover {
    background: linear-gradient(135deg, #15803d, #166534);
}

.form-actions .btn-secondary {
    background: #f5f5f5;
    color: #666;
    border: 1.5px solid #e0e0e0;
}

.form-actions .btn-secondary:hover {
    background: #e0e0e0;
}

/* Profile Preview */
.profile-preview {
    margin-top: 4px;
}

.profile-preview a {
    color: #ff9800;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 11px;
}

/* Existing Image Preview */
.existing-image-preview {
    margin-top: 6px;
    padding: 6px 10px;
    background: #fff8e1;
    border-radius: 6px;
    border: 1px solid #ffe0b2;
}

.existing-image-preview a {
    color: #ff9800;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 11px;
}

/* Responsive */
@media (max-width: 768px) {
    .search-row {
        flex-direction: column;
    }
    .search-input-wrapper {
        min-width: 100%;
    }
    .form-actions {
        flex-direction: column;
    }
    .form-actions .btn {
        justify-content: center;
    }
}
</style>

<?php if ($error): ?>
<div class="alert alert-error" data-auto-hide>
    <i class="fas fa-exclamation-circle alert-icon"></i>
    <?php echo $error; ?>
</div>
<?php endif; ?>

<div class="report-container">
<form method="POST" action="" enctype="multipart/form-data">
    <input type="hidden" id="patient_id" name="patient_id" value="<?php echo htmlspecialchars($displayData['patient_id'] ?? ''); ?>">
    <input type="hidden" id="patient_dirty" name="patient_dirty" value="<?php echo htmlspecialchars($displayData['patient_dirty'] ?? '0'); ?>">
    <input type="hidden" id="patient_conditions_loaded" name="patient_conditions_loaded" value="<?php echo htmlspecialchars($displayData['patient_conditions_loaded'] ?? '1'); ?>">

    <!-- Page Header -->
    <div class="report-header">
        <div class="report-title">
            <div class="icon"><i class="fas fa-edit"></i></div>
            <h2>دەستکاری سەردان</h2>
        </div>
        <span class="edit-mode-badge">
            <i class="fas fa-pen"></i> دۆخی دەستکاری
        </span>
    </div>

    <!-- Patient Search Card -->
    <div class="search-card">
        <div class="search-row">
            <div class="search-input-wrapper">
                <input type="text" id="patient_search" placeholder="گەڕان بە ناو یان ژمارەی مۆبایل..." autocomplete="off"
                       value="<?php echo htmlspecialchars(($displayData['full_name'] ?? '') . ' - ' . ($displayData['phone'] ?? '')); ?>">
                <div id="patient_search_results" class="search-results"></div>
            </div>
            <button type="button" id="clear_patient_selection" class="btn-clear" style="<?php echo !empty($displayData['patient_id']) ? 'display:flex;' : 'display:none;'; ?>">
                <i class="fas fa-times"></i> پاککردنەوە
            </button>
        </div>
    </div>

    <!-- Basic Patient Info Card -->
    <div class="form-card">
        <div class="form-card-header">
            <div class="card-icon icon-blue"><i class="fas fa-user"></i></div>
            <h3>زانیاری سەرەکی</h3>
        </div>
        <div class="form-card-body">
            <div class="form-grid">
                <div class="form-group">
                    <label><span class="required">*</span> ناوی تەواو</label>
                    <input type="text" id="full_name" name="full_name" class="<?php echo isset($errors['full_name']) ? 'error' : ''; ?>"
                           value="<?php echo htmlspecialchars($displayData['full_name'] ?? ''); ?>" required placeholder="ناوی سیانی">
                </div>
                <div class="form-group">
                    <label><span class="required">*</span> ڕەگەز</label>
                    <select id="gender" name="gender" class="<?php echo isset($errors['gender']) ? 'error' : ''; ?>" required>
                        <option value="">هەڵبژاردن</option>
                        <option value="male" <?php echo (($displayData['gender'] ?? '') === 'male') ? 'selected' : ''; ?>>نێر</option>
                        <option value="female" <?php echo (($displayData['gender'] ?? '') === 'female') ? 'selected' : ''; ?>>مێ</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>بەرواری لەدایکبوون</label>
                    <input type="date" id="date_of_birth" name="date_of_birth" value="<?php echo htmlspecialchars($displayData['date_of_birth'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>جۆری خوێن</label>
                    <select id="blood_type" name="blood_type">
                        <option value="">دیاری نەکراو</option>
                        <?php
                        $blood_types = ['A+','A-','B+','B-','AB+','AB-','O+','O-'];
                        $selected_blood = $displayData['blood_type'] ?? '';
                        foreach ($blood_types as $bt):
                            $selected = ($selected_blood === $bt) ? 'selected' : '';
                            echo "<option value=\"{$bt}\" {$selected}>{$bt}</option>";
                        endforeach;
                        ?>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <!-- Contact Info Card -->
    <div class="form-card">
        <div class="form-card-header">
            <div class="card-icon icon-green"><i class="fas fa-phone"></i></div>
            <h3>زانیاری پەیوەندی</h3>
        </div>
        <div class="form-card-body">
            <div class="form-grid">
                <div class="form-group">
                    <label><span class="required">*</span> ژمارەی مۆبایل</label>
                    <input type="tel" id="phone" name="phone" class="<?php echo isset($errors['phone']) ? 'error' : ''; ?>"
                           value="<?php echo htmlspecialchars($displayData['phone'] ?? ''); ?>" required placeholder="0750-123-4567">
                </div>
                <div class="form-group">
                    <label>ژمارەی دووەم</label>
                    <input type="tel" id="phone2" name="phone2" value="<?php echo htmlspecialchars($displayData['phone2'] ?? ''); ?>" placeholder="0770-987-6543">
                </div>
                <div class="form-group">
                    <label>شار</label>
                    <?php $selected_city = $displayData['city'] ?? ''; ?>
                    <select id="city" name="city">
                        <option value="">â€” هەڵبژێرە â€”</option>
                        <?php foreach ($cities_grouped as $province => $list): ?>
                            <optgroup label="<?php echo htmlspecialchars($province); ?>">
                                <?php foreach ($list as $city_name): ?>
                                    <option value="<?php echo htmlspecialchars($city_name); ?>" <?php echo ($selected_city === $city_name) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($city_name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                        <?php
                        $known_cities = !empty($cities_grouped) ? array_merge(...array_values($cities_grouped)) : [];
                        if ($selected_city !== '' && !in_array($selected_city, $known_cities, true)):
                        ?>
                            <option value="<?php echo htmlspecialchars($selected_city); ?>" selected><?php echo htmlspecialchars($selected_city); ?></option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>پیشە</label>
                    <input type="text" id="occupation" name="occupation" value="<?php echo htmlspecialchars($displayData['occupation'] ?? ''); ?>" placeholder="کارمەند">
                </div>
                <div class="form-group span-2">
                    <label>ناونیشان</label>
                    <input type="text" id="address" name="address" value="<?php echo htmlspecialchars($displayData['address'] ?? ''); ?>" placeholder="ناونیشانی وردی ماڵەوە...">
                </div>
                <div class="form-group">
                    <label>وێنەی نەخۆش</label>
                    <input type="file" id="profile_image" name="profile_image" accept="image/*">
                    <?php if (!empty($displayData['existing_profile_image'])): ?>
                    <div class="existing-image-preview">
                        <a href="<?= APP_URL ?>/<?= htmlspecialchars($displayData['existing_profile_image']) ?>" target="_blank">
                            <i class="fas fa-external-link-alt"></i> بینینی وێنە
                        </a>
                    </div>
                    <?php endif; ?>
                    <div id="profile_image_preview" class="profile-preview" style="display:none;">
                        <a id="profile_image_preview_link" href="#" target="_blank"><i class="fas fa-external-link-alt"></i> بینین</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Medical Conditions Card -->
    <div class="form-card">
        <div class="form-card-header">
            <div class="card-icon icon-pink"><i class="fas fa-heartbeat"></i></div>
            <h3>ئاگاداری تەندروستی</h3>
        </div>
        <div class="form-card-body">
            <div class="conditions-grid">
                <?php foreach ($MEDICAL_CONDITIONS as $key => $label): ?>
                    <?php
                    $isChecked = in_array($key, $displayData['medical_conditions'] ?? []);
                    $condDetails = $displayData['condition_details'][$key] ?? '';
                    $condSeverity = $displayData['condition_severity'][$key] ?? 'medium';
                    ?>
                    <div class="condition-card <?php echo $isChecked ? 'active' : ''; ?>" id="card_<?php echo $key; ?>" onclick="toggleConditionCard('<?php echo $key; ?>')">
                        <div class="condition-header">
                            <input type="checkbox" name="medical_conditions[]" value="<?php echo $key; ?>"
                                   id="condition_<?php echo $key; ?>" class="condition-checkbox"
                                   onclick="event.stopPropagation(); toggleConditionDetails('<?php echo $key; ?>')"
                                   <?php echo $isChecked ? 'checked' : ''; ?>>
                            <label for="condition_<?php echo $key; ?>"><?php echo $label; ?></label>
                        </div>
                        <div id="details_<?php echo $key; ?>" class="condition-details" onclick="event.stopPropagation()">
                            <input type="text" name="condition_details[<?php echo $key; ?>]" placeholder="وردەکاری..." value="<?php echo htmlspecialchars($condDetails); ?>">
                            <select name="condition_severity[<?php echo $key; ?>]">
                                <option value="low" <?php echo ($condSeverity === 'low') ? 'selected' : ''; ?>>کەم</option>
                                <option value="medium" <?php echo ($condSeverity === 'medium') ? 'selected' : ''; ?>>مامناوەند</option>
                                <option value="high" <?php echo ($condSeverity === 'high') ? 'selected' : ''; ?>>زۆر</option>
                            </select>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Patient Notes Card -->
    <div class="form-card">
        <div class="form-card-header">
            <div class="card-icon icon-orange"><i class="fas fa-sticky-note"></i></div>
            <h3>تێبینی نەخۆش</h3>
        </div>
        <div class="form-card-body">
            <div class="form-group">
                <textarea id="patient_notes" name="patient_notes" rows="2" placeholder="تێبینی تایبەت بۆ نەخۆش..."><?php echo htmlspecialchars($displayData['patient_notes'] ?? ''); ?></textarea>
            </div>
        </div>
    </div>

    <!-- Visit Type Card -->
    <div class="form-card">
        <div class="form-card-header">
            <div class="card-icon icon-orange"><i class="fas fa-sticky-note"></i></div>
            <h3>زانیاری سەرەکی سەردان</h3>
        </div>
        <div class="form-card-body">
            <div class="form-grid">
                <div class="form-group">
                    <label><span class="required">*</span> جۆری نەخۆش</label>
                    <div class="radio-group">
                        <label class="radio-label">
                            <input type="radio" name="visit_type" value="checkup" required onchange="toggleCheckupSection()" <?php echo (($displayData['visit_type'] ?? 'checkup') === 'checkup') ? 'checked' : ''; ?>>
                            <span>پشکنین</span>
                        </label>
                        <label class="radio-label">
                            <input type="radio" name="visit_type" value="treatment" required onchange="toggleCheckupSection()" <?php echo (($displayData['visit_type'] ?? '') === 'treatment') ? 'checked' : ''; ?>>
                            <span>ئیش</span>
                        </label>
                    </div>
                </div>
                <div class="form-group span-2">
                    <label>جۆری ئیش</label>
                    <select id="work_type_id" name="work_type_id">
                        <option value="">â€” هەڵبژێرە â€”</option>
                        <?php
                        if ($work_types_for_modal) {
                            $work_types_for_modal->data_seek(0);
                            $selected_work_type = $displayData['work_type_id'] ?? '';
                            while ($wt = $work_types_for_modal->fetch_assoc()):
                        ?>
                            <option value="<?php echo $wt['id']; ?>" <?php echo ((string)$wt['id'] === (string)$selected_work_type) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($wt['work_name']); ?>
                            </option>
                        <?php
                            endwhile;
                        }
                        ?>
                    </select>
                </div>
                <div class="form-group" id="checkupPriceGroup">
                    <label>Price</label>
                    <input type="number" id="price" name="price" min="0" step="0.01" inputmode="decimal"
                           value="<?php echo htmlspecialchars($displayData['price'] ?? ''); ?>" placeholder="0.00">
                </div>
                <div class="form-group">
                    <label><span class="required">*</span> دۆخی نەخۆش</label>
                    <div class="radio-group">
                        <label class="radio-label">
                            <input type="radio" name="patient_status" value="new" required <?php echo ($displayData['patient_status'] ?? '') === 'new' ? 'checked' : ''; ?>>
                            <span>نوێ</span>
                        </label>
                        <label class="radio-label">
                            <input type="radio" name="patient_status" value="old" required <?php echo ($displayData['patient_status'] ?? '') === 'old' ? 'checked' : ''; ?>>
                            <span>کۆن</span>
                        </label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="checkupSection">

    <!-- Doctor Information Card -->
    <div class="form-card">
        <div class="form-card-header">
            <div class="card-icon icon-purple"><i class="fas fa-user-md"></i></div>
            <h3>زانیاری دکتۆر</h3>
        </div>
        <div class="form-card-body">
            <div class="form-grid-3">
                <div class="form-group">
                    <label>دکتۆری داواکراو</label>
                    <select id="requested_doctor_id" name="requested_doctor_id">
                        <option value="">هەڵبژاردن...</option>
                        <?php while ($doctor = $doctors->fetch_assoc()): ?>
                            <option value="<?php echo $doctor['id']; ?>" <?php echo ($displayData['requested_doctor_id'] ?? '') == $doctor['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($doctor['name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>دکتۆری بینراو</label>
                    <select id="assigned_doctor_id" name="assigned_doctor_id">
                        <option value="">هەڵبژاردن...</option>
                        <?php
                        $doctors->data_seek(0);
                        while ($doctor = $doctors->fetch_assoc()):
                        ?>
                            <option value="<?php echo $doctor['id']; ?>" <?php echo ($displayData['assigned_doctor_id'] ?? '') == $doctor['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($doctor['name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>هۆکاری دیاریکردن</label>
                    <input type="text" id="assigned_doctor_reason" name="assigned_doctor_reason"
                           value="<?php echo htmlspecialchars($displayData['assigned_doctor_reason'] ?? ''); ?>" placeholder="هۆکار..." autocomplete="off">
                </div>
            </div>
        </div>
    </div>

    <!-- Time & Visit Details Card -->
    <div class="form-card">
        <div class="form-card-header">
            <div class="card-icon icon-amber"><i class="fas fa-clock"></i></div>
            <h3>کات و وردەکاری سەردان</h3>
        </div>
        <div class="form-card-body">
            <div class="form-grid">
                <div class="form-group">
                    <label><span class="required">*</span> کاتی هاتن</label>
                    <input type="time" id="arrival_time" name="arrival_time" required
                           value="<?php echo htmlspecialchars($displayData['arrival_time'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>کاتی چوونەژوورەوە</label>
                    <input type="time" id="checkin_time" name="checkin_time"
                           value="<?php echo htmlspecialchars($displayData['checkin_time'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>کاتی ئاگادارکردنەوە</label>
                    <input type="time" id="notification_time" name="notification_time"
                           value="<?php echo htmlspecialchars($displayData['notification_time'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>بەرواری سەردان</label>
                    <input type="date" id="visit_date" name="visit_date"
                           value="<?php echo htmlspecialchars($displayData['visit_date'] ?? date('Y-m-d')); ?>">
                </div>
                <div class="form-group span-2">
                    <label>ئیش (چارەسەر)</label>
                    <input type="text" id="procedure_treatment" name="procedure_treatment" list="procedures_list"
                           value="<?php echo htmlspecialchars($displayData['procedure_treatment'] ?? ''); ?>" placeholder="هەڵبژاردن یان نوسین..." autocomplete="off">
                    <datalist id="procedures_list">
                        <?php while ($proc = $procedures->fetch_assoc()): ?>
                            <option value="<?php echo htmlspecialchars($proc['name']); ?>">
                        <?php endwhile; ?>
                    </datalist>
                </div>
                <div class="form-group">
                    <label><span class="required">*</span> دۆخی نەخۆش</label>
                    <div class="radio-group">
                        <label class="radio-label">
                            <input type="radio" name="patient_status_checkup_view" value="new" disabled
                                   <?php echo ($displayData['patient_status'] ?? '') === 'new' ? 'checked' : ''; ?>>
                            <span>نوێ</span>
                        </label>
                        <label class="radio-label">
                            <input type="radio" name="patient_status_checkup_view" value="old" disabled
                                   <?php echo ($displayData['patient_status'] ?? '') === 'old' ? 'checked' : ''; ?>>
                            <span>کۆن</span>
                        </label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Administrative Card -->
    <div class="form-card">
        <div class="form-card-header">
            <div class="card-icon icon-teal"><i class="fas fa-cog"></i></div>
            <h3>زانیاری ئیداری</h3>
        </div>
        <div class="form-card-body">
            <div class="form-grid">
                <div class="form-group">
                    <label>کارمەندی پێشوازی</label>
                    <select id="receptionist_id" name="receptionist_id">
                        <option value="">هەڵبژاردن...</option>
                        <?php while ($receptionist = $receptionists->fetch_assoc()): ?>
                            <option value="<?php echo $receptionist['id']; ?>" <?php echo ($displayData['receptionist_id'] ?? '') == $receptionist['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($receptionist['full_name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>سەرچاوەی هاتن</label>
                    <input type="text" id="referral_source" name="referral_source" list="referral_sources_list"
                           value="<?php echo htmlspecialchars($displayData['referral_source'] ?? ''); ?>" placeholder="لەڕێگەی کێ هاتووە..." autocomplete="off">
                    <datalist id="referral_sources_list">
                        <?php while ($source = $referral_sources->fetch_assoc()): ?>
                            <option value="<?php echo htmlspecialchars($source['name']); ?>">
                        <?php endwhile; ?>
                    </datalist>
                </div>
                <div class="form-group">
                    <label>تێبینی سەرچاوە</label>
                    <input type="text" id="referral_source_notes" name="referral_source_notes" list="doctors_list_referral"
                           value="<?php echo htmlspecialchars($displayData['referral_source_notes'] ?? ''); ?>" placeholder="تێبینی..." autocomplete="off">
                    <datalist id="doctors_list_referral">
                        <?php
                        $doctors->data_seek(0);
                        while ($doctor = $doctors->fetch_assoc()):
                        ?>
                            <option value="<?php echo htmlspecialchars($doctor['name']); ?>">
                        <?php endwhile; ?>
                    </datalist>
                </div>
                <div class="form-group">
                    <label>بۆ ئیش بۆ کرا</label>
                    <div class="radio-group">
                        <label class="radio-label">
                            <input type="radio" name="treated" value="yes" onchange="toggleTreatedReason()"
                                   <?php echo ($displayData['treated'] ?? '') === 'yes' ? 'checked' : ''; ?>>
                            <span>بەڵێ</span>
                        </label>
                        <label class="radio-label">
                            <input type="radio" name="treated" value="no" onchange="toggleTreatedReason()"
                                   <?php echo ($displayData['treated'] ?? 'no') === 'no' ? 'checked' : ''; ?>>
                            <span>نەخێر</span>
                        </label>
                    </div>
                </div>
            </div>
            <div id="treated_reason_container" style="margin-top: 10px; display: <?php echo ($displayData['treated'] ?? 'no') === 'no' ? 'block' : 'none'; ?>;">
                <div class="form-group">
                    <label>هۆکاری چارەسەر نەکردن</label>
                    <input type="text" id="treated_reason" name="treated_reason"
                           value="<?php echo htmlspecialchars($displayData['treated_reason'] ?? ''); ?>" placeholder="بۆچی چارەسەر نەکرا؟" autocomplete="off">
                </div>
            </div>
        </div>
    </div>

    <!-- Appointment Card -->
    <div class="form-card">
        <div class="form-card-header">
            <div class="card-icon icon-indigo"><i class="fas fa-calendar-check"></i></div>
            <h3>زانیاری چاوپێکەوتن</h3>
        </div>
        <div class="form-card-body">
            <?php if ($linkedPlan):
                $lpState = TreatmentPlan::designationState($linkedPlan);
                $lpTreatingName = TreatmentPlan::treatingDoctorId($linkedPlan) === (int)$linkedPlan['doctor_id']
                    ? ($linkedPlan['doctor_name'] ?? '') : ($linkedPlan['treating_doctor_name'] ?? '');
                $lpText = [
                    'undecided'          => 'دکتۆرەکە هێشتا بڕیاری نەداوە کێ چارەسەر دەکات — دەتوانیت خانەکان خۆت پڕ بکەیتەوە.',
                    'self'               => 'دکتۆری پشکنەر خۆی چارەسەری دەکات.',
                    'doctor'             => 'نێردراوە بۆ ' . $lpTreatingName . '.',
                    'awaiting_reception' => '«چەند دکتۆرێک» — پێشوازی دەبێت لەناو پلانەکە دکتۆرێک هەڵبژێرێت.',
                    'chosen'             => 'پێشوازی ناردی بۆ ' . $lpTreatingName . '.',
                ][$lpState];
            ?>
            <div class="visit-plan-link <?= $planDrivesRouting ? 'is-locked' : '' ?>">
                <i class="fas fa-clipboard-list"></i>
                <div>
                    <b>پلانی چارەسەر:</b>
                    <a href="<?= APP_URL ?>/treatment-plans/view.php?id=<?= (int)$linkedPlan['id'] ?>" target="_blank"><bdi><?= htmlspecialchars($linkedPlan['plan_number']) ?></bdi></a>
                    — <?= htmlspecialchars($linkedPlan['doctor_name'] ?? '') ?>.
                    <?= htmlspecialchars($lpText) ?>
                    <?php if ($planDrivesRouting): ?>
                    <small>ئەم خانانە لە پلانەکەوە پڕدەکرێنەوە و لێرە ناگۆڕدرێن؛ بۆ گۆڕین پلانەکە بکەرەوە.</small>
                    <?php endif; ?>
                </div>
            </div>
            <style>
            .visit-plan-link { display: flex; gap: 10px; align-items: flex-start; margin-bottom: 14px; padding: 10px 13px; border-radius: 9px; background: #f5f3ff; border: 1px solid #ddd6fe; font-size: 13px; line-height: 1.7; color: #3b2f6b; }
            .visit-plan-link > i { color: #6d28d9; font-size: 16px; margin-top: 3px; }
            .visit-plan-link small { display: block; color: #6b7280; }
            .visit-plan-link.is-locked { background: #eef2ff; border-color: #c7d2fe; }
            </style>
            <?php endif; ?>
            <div class="form-grid">
                <div class="form-group">
                    <label>بەرواری چاوپێکەوتن</label>
                    <input type="date" id="appointment_date" name="appointment_date"
                           value="<?php echo htmlspecialchars($displayData['appointment_date'] ?? ''); ?>"<?= $routingLockAttr ?>>
                </div>
                <div class="form-group">
                    <label>دکتۆری دەستنیشانکەر</label>
                    <select id="referring_doctor_id" name="referring_doctor_id"<?= $routingLockAttr ?>>
                        <option value="">هەڵبژاردن...</option>
                        <?php
                        $doctors->data_seek(0);
                        while ($doctor = $doctors->fetch_assoc()):
                        ?>
                            <option value="<?php echo $doctor['id']; ?>" <?php echo ($displayData['referring_doctor_id'] ?? '') == $doctor['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($doctor['name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>دکتۆری دەستنیشانکراو</label>
                    <select id="referring_doctor_designation" name="referring_doctor_designation" onchange="toggleReferringDoctorChoice()"<?= $routingLockAttr ?>>
                        <option value="">هەڵبژاردن...</option>
                        <option value="DOCTORS" <?php echo ($displayData['referring_doctor_designation'] ?? '') === 'DOCTORS' ? 'selected' : ''; ?>>چەند دکتۆرێک</option>
                        <option value="PATIENT" <?= ($displayData['referring_doctor_designation'] ?? '') === 'PATIENT' ? 'selected' : '' ?>>نەخۆش بەخۆی دیاریکرد</option>
                        <?php
                        $doctors->data_seek(0);
                        while ($doctor = $doctors->fetch_assoc()):
                        ?>
                            <option value="<?php echo $doctor['id']; ?>" <?php echo ($displayData['referring_doctor_designation'] ?? '') == $doctor['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($doctor['name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>پلانی چارەسەر</label>
                    <input type="file" id="appointment_image" name="appointment_image" accept="image/*,.pdf">
                    <?php if (!empty($visit['appointment_image'])): ?>
                    <div class="existing-image-preview">
                        <a href="<?= APP_URL ?>/<?= htmlspecialchars($visit['appointment_image']) ?>" target="_blank">
                            <i class="fas fa-external-link-alt"></i> بینینی فایل
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <div id="referring_doctor_choice_container" style="display: <?= in_array($displayData['referring_doctor_designation'] ?? '', ['DOCTORS', 'PATIENT'], true) ? 'block' : 'none' ?>; margin-top: 10px;">
                <div class="form-grid-2">
                    <div class="form-group">
                        <label>کارمەندی دەستنیشانکەر</label>
                        <select id="appointment_added_by" name="appointment_added_by"<?= $routingLockAttr ?>>
                            <option value="">هەڵبژاردن...</option>
                            <?php
                            $receptionists->data_seek(0);
                            while ($receptionist = $receptionists->fetch_assoc()):
                            ?>
                                <option value="<?php echo $receptionist['id']; ?>" <?php echo ($displayData['appointment_added_by'] ?? '') == $receptionist['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($receptionist['full_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>دکتۆری هەڵبژێردراو</label>
                        <select id="referring_doctor_choice_id" name="referring_doctor_choice_id"<?= $routingLockAttr ?>>
                            <option value="">هەڵبژاردن...</option>
                            <?php
                            $doctors->data_seek(0);
                            while ($doctor = $doctors->fetch_assoc()):
                            ?>
                                <option value="<?php echo $doctor['id']; ?>" <?php echo ($displayData['referring_doctor_choice_id'] ?? '') == $doctor['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($doctor['name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>
            </div>
            <?php if (!empty($errors['patient_referral'])): ?><div role="alert" style="color:#c62828;"><?= htmlspecialchars($errors['patient_referral']) ?></div><?php endif; ?>
        </div>
    </div>

    <!-- Notes Card -->
    <div class="form-card">
        <div class="form-card-header">
            <div class="card-icon icon-green"><i class="fas fa-comment-alt"></i></div>
            <h3>تێبینی سەردان</h3>
        </div>
        <div class="form-card-body">
            <div class="form-group">
                <textarea id="notes" name="notes" rows="2" placeholder="تێبینی زیادە..."><?php echo htmlspecialchars($displayData['notes'] ?? ''); ?></textarea>
            </div>
        </div>
    </div>

    </div>

    <!-- Form Actions -->
    <div class="form-actions">
        <a href="<?= APP_URL ?>/visits.php" class="btn btn-secondary">
            <i class="fas fa-times"></i> هەڵوەشاندنەوە
        </a>
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-save"></i> نوێکردنەوە
        </button>
        <button type="submit" name="send_to_waiting" value="1" class="btn btn-primary btn-save-send">
            <i class="fas fa-user-clock"></i> نوێکردنەوە و ناردن بۆ ژووری چاوەڕوانی
        </button>
    </div>
</form>
</div>

<script>
// Toggle referring doctor choice field
function toggleReferringDoctorChoice() {
    const referringDoctorDesignation = document.getElementById('referring_doctor_designation');
    const choiceContainer = document.getElementById('referring_doctor_choice_container');

    if (['DOCTORS', 'PATIENT'].includes(referringDoctorDesignation.value)) {
        choiceContainer.style.display = 'block';
    } else {
        choiceContainer.style.display = 'none';
    }
    const staffSelect = document.getElementById('appointment_added_by');
    if (staffSelect) staffSelect.closest('.form-group').style.display = referringDoctorDesignation.value === 'PATIENT' ? 'none' : '';
}
document.addEventListener('DOMContentLoaded', toggleReferringDoctorChoice);

// Toggle treated reason field
function toggleTreatedReason() {
    const treatedRadios = document.getElementsByName('treated');
    const reasonContainer = document.getElementById('treated_reason_container');
    let selectedValue = '';

    for (const radio of treatedRadios) {
        if (radio.checked) {
            selectedValue = radio.value;
            break;
        }
    }

    if (selectedValue === 'no') {
        reasonContainer.style.display = 'block';
    } else {
        reasonContainer.style.display = 'none';
    }
}

function toggleCheckupSection() {
    const section = document.getElementById('checkupSection');
    const arrival = document.getElementById('arrival_time');
    const priceGroup = document.getElementById('checkupPriceGroup');
    const priceInput = document.getElementById('price');
    if (!section) return;

    const radios = document.getElementsByName('visit_type');
    let value = '';
    for (const radio of radios) {
        if (radio.checked) {
            value = radio.value;
            break;
        }
    }

    if (value === 'treatment') {
        section.style.display = 'none';
        if (priceGroup) priceGroup.style.display = 'none';
        if (priceInput) priceInput.value = '0';
        if (arrival) arrival.removeAttribute('required');
    } else {
        section.style.display = '';
        if (priceGroup) priceGroup.style.display = '';
        if (arrival) arrival.setAttribute('required', 'required');
    }
}

// Patient medical conditions cards
function markPatientDirty() {
    const dirtyInput = document.getElementById('patient_dirty');
    if (dirtyInput) {
        dirtyInput.value = '1';
    }
}

function toggleConditionDetails(key) {
    const checkbox = document.getElementById('condition_' + key);
    const details = document.getElementById('details_' + key);
    const card = document.getElementById('card_' + key);

    if (!checkbox || !details || !card) {
        return;
    }

    if (checkbox.checked) {
        details.style.display = 'block';
        card.classList.add('active');
    } else {
        details.style.display = 'none';
        card.classList.remove('active');
    }

    markPatientDirty();
}

function toggleConditionCard(key) {
    const checkbox = document.getElementById('condition_' + key);
    if (!checkbox) return;
    checkbox.checked = !checkbox.checked;
    toggleConditionDetails(key);
}

document.addEventListener('DOMContentLoaded', function() {
    toggleCheckupSection();

    // Initialize active cards
    const checkboxes = document.querySelectorAll('.condition-checkbox');
    checkboxes.forEach(checkbox => {
        if (checkbox.checked) {
            const key = checkbox.value;
            const card = document.getElementById('card_' + key);
            if (card) card.classList.add('active');
        }
    });

    // Mark patient info as "dirty" when user edits any patient field
    const patientFieldIds = [
        'full_name', 'gender', 'date_of_birth', 'phone', 'phone2', 'blood_type',
        'city', 'occupation', 'address', 'patient_notes', 'profile_image'
    ];
    patientFieldIds.forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('input', markPatientDirty);
        el.addEventListener('change', markPatientDirty);
    });

    document.querySelectorAll('.condition-details input, .condition-details select').forEach(el => {
        el.addEventListener('input', markPatientDirty);
        el.addEventListener('change', markPatientDirty);
    });

    // Live patient search (AJAX) + auto-fill
    const searchInput = document.getElementById('patient_search');
    const resultsBox = document.getElementById('patient_search_results');
    const clearBtn = document.getElementById('clear_patient_selection');
    const patientIdInput = document.getElementById('patient_id');
    const dirtyInput = document.getElementById('patient_dirty');
    const conditionsLoadedInput = document.getElementById('patient_conditions_loaded');
    const profilePreview = document.getElementById('profile_image_preview');
    const profilePreviewLink = document.getElementById('profile_image_preview_link');

    let searchTimer = null;

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, (ch) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        }[ch]));
    }

    function setDirty(value) {
        if (dirtyInput) dirtyInput.value = String(value);
    }

    function hideResults() {
        if (!resultsBox) return;
        resultsBox.style.display = 'none';
        resultsBox.innerHTML = '';
    }

    function updateProfilePreview(path) {
        if (!profilePreview || !profilePreviewLink) return;
        if (path) {
            profilePreviewLink.href = '<?= APP_URL ?>/' + path;
            profilePreview.style.display = 'block';
        } else {
            profilePreviewLink.href = '#';
            profilePreview.style.display = 'none';
        }
    }

    function setFieldValue(id, value) {
        const el = document.getElementById(id);
        if (!el) return;
        el.value = value ?? '';
    }

    function clearMedicalConditionsUI() {
        document.querySelectorAll('.condition-checkbox').forEach(cb => {
            cb.checked = false;
            const key = cb.value;
            const details = document.getElementById('details_' + key);
            const card = document.getElementById('card_' + key);
            if (details) details.style.display = 'none';
            if (card) card.classList.remove('active');
        });

        document.querySelectorAll('.condition-details input[type="text"]').forEach(inp => inp.value = '');
        document.querySelectorAll('.condition-details select').forEach(sel => sel.value = 'medium');
    }

    function applyMedicalConditions(conditions) {
        clearMedicalConditionsUI();

        if (!Array.isArray(conditions)) return;

        conditions.forEach(cond => {
            const key = cond?.type;
            if (!key) return;

            const checkbox = document.getElementById('condition_' + key);
            const details = document.getElementById('details_' + key);
            const card = document.getElementById('card_' + key);

            if (checkbox) checkbox.checked = true;
            if (details) details.style.display = 'block';
            if (card) card.classList.add('active');

            if (details) {
                const detailsInput = details.querySelector('input[type="text"]');
                const severitySelect = details.querySelector('select');
                if (detailsInput) detailsInput.value = cond.details ?? '';
                if (severitySelect) severitySelect.value = cond.severity ?? 'medium';
            }
        });
    }

    function selectPatient(patient) {
        if (!patientIdInput) return;

        patientIdInput.value = String(patient.id ?? '');
        if (conditionsLoadedInput) conditionsLoadedInput.value = '1';

        setDirty(0);

        setFieldValue('full_name', patient.full_name ?? '');
        setFieldValue('gender', patient.gender ?? '');
        setFieldValue('date_of_birth', patient.date_of_birth ?? '');
        setFieldValue('phone', patient.phone ?? '');
        setFieldValue('phone2', patient.phone2 ?? '');
        setFieldValue('address', patient.address ?? '');
        setFieldValue('city', patient.city ?? '');
        setFieldValue('occupation', patient.occupation ?? '');
        setFieldValue('blood_type', patient.blood_type ?? '');
        setFieldValue('patient_notes', patient.notes ?? '');

        applyMedicalConditions(patient.medical_conditions ?? []);
        updateProfilePreview(patient.profile_image ?? null);

        if (clearBtn) clearBtn.style.display = 'inline-flex';
        if (searchInput) searchInput.value = `${patient.full_name ?? ''} - ${patient.phone ?? ''}`.trim();
        hideResults();
    }

    function clearSelection() {
        if (patientIdInput) patientIdInput.value = '';
        if (conditionsLoadedInput) conditionsLoadedInput.value = '0';
        setDirty(0);

        if (clearBtn) clearBtn.style.display = 'none';
        if (searchInput) searchInput.value = '';

        setFieldValue('full_name', '');
        setFieldValue('gender', '');
        setFieldValue('date_of_birth', '');
        setFieldValue('phone', '');
        setFieldValue('phone2', '');
        setFieldValue('address', '');
        setFieldValue('city', '');
        setFieldValue('occupation', '');
        setFieldValue('blood_type', '');
        setFieldValue('patient_notes', '');

        const profileInput = document.getElementById('profile_image');
        if (profileInput) profileInput.value = '';
        updateProfilePreview(null);

        clearMedicalConditionsUI();
        hideResults();
    }

    function renderResults(results) {
        if (!resultsBox) return;
        resultsBox.innerHTML = '';

        if (!Array.isArray(results) || results.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'search-item';
            empty.innerHTML = '<span class="name">هیچ نەخۆشێک نەدۆزرایەوە</span>';
            resultsBox.appendChild(empty);
            resultsBox.style.display = 'block';
            return;
        }

        results.forEach(p => {
            const item = document.createElement('div');
            item.className = 'search-item';
            item.innerHTML = `<span class="name">${escapeHtml(p.full_name)}${p.patient_code ? ' <span style="font-weight:600;color:#6e6e73;">(' + escapeHtml(p.patient_code) + ')</span>' : ''}</span><span class="phone">${escapeHtml(p.phone)}</span>`;
            item.addEventListener('click', () => selectPatient(p));
            resultsBox.appendChild(item);
        });

        resultsBox.style.display = 'block';
    }

    function searchPatients(term) {
        fetch(`<?= APP_URL ?>/ajax/patient_search.php?q=${encodeURIComponent(term)}`, {
            headers: { 'Accept': 'application/json' }
        })
        .then(res => res.json())
        .then(data => {
            if (data && data.success) {
                renderResults(data.results || []);
            } else {
                hideResults();
            }
        })
        .catch(() => hideResults());
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', clearSelection);
    }

    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const term = (searchInput.value || '').trim();

            if (term.length < 2) {
                hideResults();
                return;
            }

            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => searchPatients(term), 250);
        });

        searchInput.addEventListener('focus', function() {
            const term = (searchInput.value || '').trim();
            if (term.length >= 2 && resultsBox && resultsBox.innerHTML.trim() !== '') {
                resultsBox.style.display = 'block';
            }
        });
    }

    document.addEventListener('click', function(e) {
        if (!resultsBox || !searchInput) return;
        if (e.target === searchInput) return;
        if (resultsBox.contains(e.target)) return;
        hideResults();
    });
});
</script>

<?php
// Close mysqli connection
$conn->close();

require_once __DIR__ . '/includes/footer.php';
?>
