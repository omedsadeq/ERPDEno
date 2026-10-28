<?php
/**
 * New Visit Page - Full Version
 * سیستەمی زیرەکی کلینیکی ددان
 */

// Include config first to access APP_URL
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/classes/VisitDoctorAssignment.php';

// Creating a visit is reception's job — gate the whole page (including the
// POST handlers below) behind page.new_visit BEFORE any output. Doctors and
// other roles without the permission are turned away.
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Permission.php';
$gateAuth = new Auth();
if (!$gateAuth->isLoggedIn()) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}
if (!Permission::getInstance()->can('page.new_visit')) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

$pageTitle = 'سەردانی نوێ';
$breadcrumb = [
    ['title' => 'سەردانەکان', 'url' => APP_URL . '/visits.php'],
    ['title' => 'سەردانی نوێ']
];
// ?embed=1: opened in the «سەردانی نوێ» dialog (includes/new_visit_dialog.php)
// of visits.php, waiting-room.php or the dashboard — only the form, and the
// page tells its parent when the visit is saved or the dialog should close.
$embedLayout = ($_GET['embed'] ?? '') === '1';

require_once __DIR__ . '/includes/header.php';

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
$redirect_url = null; // For handling redirect after header output

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

// Get doctors for dropdown
$doctors = $conn->query("SELECT id, name FROM doctors WHERE is_active = 1 ORDER BY name");

// Reception employees only — the field is "کارمەندی پێشوازی", so doctors /
// accountants / x-ray accounts must not appear here
$receptionists = $conn->query("SELECT id, full_name FROM users
    WHERE role IN ('receptionist', 'resident_receptionist') AND is_active = 1
    ORDER BY full_name");

// Get referral sources for dropdown
$referral_sources = $conn->query("SELECT name FROM referral_sources WHERE is_active = 1 ORDER BY name");

// Get procedures for dropdown
$procedures = $conn->query("SELECT name FROM procedures WHERE is_active = 1 ORDER BY name");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::require();

    // Patient (supports old fields for compatibility)
    $patient_id = !empty($_POST['patient_id']) ? (int)$_POST['patient_id'] : null;
    $patient_dirty = isset($_POST['patient_dirty']) ? (int)$_POST['patient_dirty'] : 0;
    $patient_conditions_loaded = isset($_POST['patient_conditions_loaded']) ? (int)$_POST['patient_conditions_loaded'] : 0;

    $full_name = sanitize($_POST['full_name'] ?? ($_POST['patient_name'] ?? ''));
    $phone = sanitize($_POST['phone'] ?? ($_POST['mobile_number'] ?? ''));
    $gender = sanitize($_POST['gender'] ?? '');
    $phone2 = sanitize($_POST['phone2'] ?? '');
    $date_of_birth = !empty($_POST['date_of_birth']) ? sanitize($_POST['date_of_birth']) : null;
    $address = sanitize($_POST['address'] ?? '');
    $city = sanitize($_POST['city'] ?? '');
    $location_id = (int)($_POST['location_id'] ?? 0);   // the گەڕەک picked from شوێن
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
    // Only the treatment form posts this — the checkup card carries its own two
    // doctor boxes. Optional: the waiting-room step fills it in when left blank.
    $treatment_doctor_id = !empty($_POST['treatment_doctor_id']) ? (int)$_POST['treatment_doctor_id'] : null;
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
    $patient_status = sanitize($_POST['patient_status'] ?? '');
    if ($visit_type === 'treatment') {
        $patient_status = 'old';
        $visit_price = 0.0;
    }
    // The reception dropdown stays (shared front-desk terminals), but the value
    // must be a real active user — and created_by below always records the
    // actual logged-in user, so attribution can no longer be spoofed.
    $receptionist_id = !empty($_POST['receptionist_id']) ? (int)$_POST['receptionist_id'] : (int)$currentUser['id'];
    $receptionistOk = $conn->query("SELECT id FROM users WHERE id = " . (int)$receptionist_id . " AND is_active = 1");
    if (!$receptionistOk || $receptionistOk->num_rows === 0) {
        $receptionist_id = (int)$currentUser['id'];
    }
    $referral_source = sanitize($_POST['referral_source'] ?? '');
    $referral_source_notes = !empty($_POST['referral_source_notes']) ? sanitize($_POST['referral_source_notes']) : null;
    $appointment_date = !empty($_POST['appointment_date']) ? sanitize($_POST['appointment_date']) : null;
    $appointment_added_by = !empty($_POST['appointment_added_by']) ? (int)$_POST['appointment_added_by'] : (int)$currentUser['id'];
    $addedByOk = $conn->query("SELECT id FROM users WHERE id = " . (int)$appointment_added_by . " AND is_active = 1");
    if (!$addedByOk || $addedByOk->num_rows === 0) {
        $appointment_added_by = (int)$currentUser['id'];
    }
    $appointment_doctor_id = null; // Always set to NULL
    $referring_doctor_id = !empty($_POST['referring_doctor_id']) ? (int)$_POST['referring_doctor_id'] : null;
    $referring_doctor_designation = !empty($_POST['referring_doctor_designation']) ? sanitize($_POST['referring_doctor_designation']) : null;
    $referring_doctor_choice_id = !empty($_POST['referring_doctor_choice_id']) ? (int)$_POST['referring_doctor_choice_id'] : null;
    if ($visit_type === 'checkup' && $referring_doctor_designation === 'PATIENT' && (!$referring_doctor_id || !$referring_doctor_choice_id)) {
        $errors['patient_referral'] = 'بۆ هەڵبژاردنی نەخۆش، دکتۆری پشکنەر و دکتۆری هەڵبژێردراو دیاری بکە';
    }
    $notes = sanitize($_POST['notes'] ?? '');
    $visit_date = !empty($_POST['visit_date']) ? sanitize($_POST['visit_date']) : date('Y-m-d');

    // Patient validations (same rules as patients/add.php)
    if (empty($full_name)) {
        $errors['full_name'] = 'ناو پێویستە';
    }
    if (empty($phone)) {
        $errors['phone'] = 'ژمارەی مۆبایل پێویستە';
    }
    if (empty($gender)) {
        $errors['gender'] = 'ڕەگەز پێویستە';
    }

    // Required visit fields — arrival_time only required when visit_type=checkup
    // (the entire 'زانیاری سەردان بۆ پشکنین' section is hidden for treatment visits)
    $needsCheckupFields = $visit_type === 'checkup';
    if (($needsCheckupFields && empty($arrival_time)) || empty($patient_status) || empty($visit_type)) {
        $errors['visit_required'] = 'تکایە خانە پێویستەکانی سەردان پڕ بکەوە';
    }
    if (empty($visit_type)) {
        $errors['visit_type'] = 'جۆری نەخۆش پێویستە هەڵبژێردرێت';
    }
    $doctor_assignment_source = $needsCheckupFields ? VisitDoctorAssignment::derive($requested_doctor_id, $assigned_doctor_id) : null;

    // Function to compress image
    function compressImage($source, $destination, $quality = 75) {
        $info = getimagesize($source);
        $mime = $info['mime'];

        // Create image from source
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

        // Get original dimensions
        $width = imagesx($image);
        $height = imagesy($image);

        // Calculate new dimensions (max width/height: 1200px)
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

        // Create new image with new dimensions
        $new_image = imagecreatetruecolor($new_width, $new_height);

        // Preserve transparency for PNG and GIF
        if ($mime == 'image/png' || $mime == 'image/gif') {
            imagealphablending($new_image, false);
            imagesavealpha($new_image, true);
            $transparent = imagecolorallocatealpha($new_image, 255, 255, 255, 127);
            imagefilledrectangle($new_image, 0, 0, $new_width, $new_height, $transparent);
        }

        // Copy and resize
        imagecopyresampled($new_image, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);

        // Save compressed image
        $saved = false;
        switch ($mime) {
            case 'image/jpeg':
                $saved = imagejpeg($new_image, $destination, $quality);
                break;
            case 'image/png':
                // PNG quality is 0-9 (compression level)
                $png_quality = floor((100 - $quality) / 11);
                $saved = imagepng($new_image, $destination, $png_quality);
                break;
            case 'image/gif':
                $saved = imagegif($new_image, $destination);
                break;
        }

        // Free memory
        imagedestroy($image);
        imagedestroy($new_image);

        return $saved;
    }

    $uploaded_files = [];

    // Handle patient profile image upload (images only)
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
    $appointment_image = null;
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

            // For PDF files, just move them
            if ($file_extension === 'pdf') {
                if (move_uploaded_file($_FILES['appointment_image']['tmp_name'], $upload_path)) {
                    $appointment_image = 'uploads/appointments/' . $new_filename;
                }
            } else {
                // For images, compress them
                $temp_path = $_FILES['appointment_image']['tmp_name'];

                // Try different quality levels to achieve 50-100KB
                $quality = 75;
                $compressed = false;

                while ($quality >= 40 && !$compressed) {
                    if (compressImage($temp_path, $upload_path, $quality)) {
                        $file_size = filesize($upload_path);

                        // If file size is between 50KB and 150KB, accept it
                        if ($file_size <= 150000) {
                            $compressed = true;
                            $appointment_image = 'uploads/appointments/' . $new_filename;
                        } elseif ($quality > 40) {
                            // Try lower quality
                            $quality -= 10;
                        } else {
                            // Accept the file even if larger
                            $compressed = true;
                            $appointment_image = 'uploads/appointments/' . $new_filename;
                        }
                    } else {
                        break;
                    }
                }

                // If compression failed, try simple move
                if (!$compressed && move_uploaded_file($temp_path, $upload_path)) {
                    $appointment_image = 'uploads/appointments/' . $new_filename;
                }
            }
        }
    }

    if (!empty($appointment_image)) {
        $uploaded_files[] = __DIR__ . '/' . $appointment_image;
    }

    if (empty($errors)) {
        // Ensure patients table exists (basic schema)
        $check_patients_table = $conn->query("SHOW TABLES LIKE 'patients'");
        if ($check_patients_table && $check_patients_table->num_rows == 0) {
            $conn->query("CREATE TABLE IF NOT EXISTS patients (
                id INT AUTO_INCREMENT PRIMARY KEY,
                patient_code VARCHAR(20) NOT NULL,
                full_name VARCHAR(255) NOT NULL,
                gender ENUM('male','female') NOT NULL,
                date_of_birth DATE DEFAULT NULL,
                phone VARCHAR(30) NOT NULL,
                phone2 VARCHAR(30) DEFAULT NULL,
                address TEXT DEFAULT NULL,
                city VARCHAR(100) DEFAULT NULL,
                occupation VARCHAR(100) DEFAULT NULL,
                blood_type VARCHAR(5) DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                profile_image VARCHAR(255) DEFAULT NULL,
                registered_by INT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_patients_phone (phone),
                UNIQUE KEY uq_patients_code (patient_code),
                KEY idx_patients_name (full_name),
                KEY idx_patients_phone2 (phone2)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        // Ensure patient_medical_conditions table exists
        $check_conditions_table = $conn->query("SHOW TABLES LIKE 'patient_medical_conditions'");
        if ($check_conditions_table && $check_conditions_table->num_rows == 0) {
            $conn->query("CREATE TABLE IF NOT EXISTS patient_medical_conditions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                patient_id INT NOT NULL,
                condition_type VARCHAR(50) NOT NULL,
                condition_details VARCHAR(255) DEFAULT NULL,
                severity ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_pmc_patient_id (patient_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        // Check if columns exist, if not add them
        $check_columns = $conn->query("SHOW COLUMNS FROM visits LIKE 'treated'");
        if ($check_columns->num_rows == 0) {
            $conn->query("ALTER TABLE visits ADD COLUMN treated VARCHAR(10) DEFAULT 'no' AFTER mobile_number");
            $conn->query("ALTER TABLE visits ADD COLUMN referral_source_notes TEXT DEFAULT NULL AFTER referral_source");
            $conn->query("ALTER TABLE visits ADD COLUMN appointment_date DATE DEFAULT NULL AFTER referral_source_notes");
            $conn->query("ALTER TABLE visits ADD COLUMN appointment_added_by INT DEFAULT NULL AFTER appointment_date");
            $conn->query("ALTER TABLE visits ADD COLUMN appointment_doctor_id INT DEFAULT NULL AFTER appointment_added_by");
            $conn->query("ALTER TABLE visits ADD COLUMN referring_doctor_id VARCHAR(100) DEFAULT NULL AFTER appointment_doctor_id");
            $conn->query("ALTER TABLE visits ADD COLUMN referring_doctor_choice_id INT DEFAULT NULL AFTER referring_doctor_id");
            $conn->query("ALTER TABLE visits ADD COLUMN appointment_image VARCHAR(255) DEFAULT NULL AFTER referring_doctor_choice_id");
        }

        // Check if referral_source_notes column exists separately (for existing databases)
        $check_notes_column = $conn->query("SHOW COLUMNS FROM visits LIKE 'referral_source_notes'");
        if ($check_notes_column->num_rows == 0) {
            $conn->query("ALTER TABLE visits ADD COLUMN referral_source_notes TEXT DEFAULT NULL AFTER referral_source");
        }

        // Check if assigned_doctor_reason column exists
        $check_reason_column = $conn->query("SHOW COLUMNS FROM visits LIKE 'assigned_doctor_reason'");
        if ($check_reason_column->num_rows == 0) {
            $conn->query("ALTER TABLE visits ADD COLUMN assigned_doctor_reason TEXT DEFAULT NULL AFTER assigned_doctor_id");
        }

        // Check if treated_reason column exists
        $check_treated_reason = $conn->query("SHOW COLUMNS FROM visits LIKE 'treated_reason'");
        if ($check_treated_reason->num_rows == 0) {
            $conn->query("ALTER TABLE visits ADD COLUMN treated_reason TEXT DEFAULT NULL AFTER treated");
        }

        // Check if referring_doctor_designation column exists
        $check_referring_designation = $conn->query("SHOW COLUMNS FROM visits LIKE 'referring_doctor_designation'");
        if ($check_referring_designation->num_rows == 0) {
            $conn->query("ALTER TABLE visits ADD COLUMN referring_doctor_designation VARCHAR(100) DEFAULT NULL AFTER referring_doctor_id");
        }

        // Ensure visits.patient_id exists
        $check_patient_id_column = $conn->query("SHOW COLUMNS FROM visits LIKE 'patient_id'");
        if ($check_patient_id_column->num_rows == 0) {
            $conn->query("ALTER TABLE visits ADD COLUMN patient_id INT DEFAULT NULL AFTER id");
        }
        $check_patient_id_index = $conn->query("SHOW INDEX FROM visits WHERE Key_name = 'idx_visits_patient_id'");
        if ($check_patient_id_index->num_rows == 0) {
            $conn->query("ALTER TABLE visits ADD INDEX idx_visits_patient_id (patient_id)");
        }

        $check_price_column = $conn->query("SHOW COLUMNS FROM visits LIKE 'price'");
        if ($check_price_column && $check_price_column->num_rows == 0) {
            $conn->query("ALTER TABLE visits ADD COLUMN price DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER procedure_treatment");
            $conn->query("ALTER TABLE visits ADD INDEX idx_visits_price (price)");
        }

        // Transaction: patient + visit (all-or-nothing)
        try {
            $conn->begin_transaction();

            // Find/create/update patient first
            $final_patient_id = null;

            if (!empty($patient_id)) {
                $existingPatient = $patientManager->getById($patient_id);
                if (!$existingPatient) {
                    throw new Exception('Patient not found');
                }

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

                $final_patient_id = $patient_id;
            } else {
                $existingId = $patientManager->findExistingIdByPhone($phone, $date_of_birth, $full_name);
                if (!empty($existingId)) {
                    $final_patient_id = $existingId;

                    // If a profile image was uploaded, update only the image to avoid orphan files
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

            // شوێن: the picked گەڕەک, its شار as the patient's city (as before)
            if ($location_id > 0) {
                $locStmt = $conn->prepare(
                    "SELECT a.name AS area, c.name AS city FROM locations a JOIN locations c ON c.id = a.parent_id
                     WHERE a.id = ? AND a.level = 'area'"
                );
                $locStmt->bind_param('i', $location_id);
                $locStmt->execute();
                $loc = $locStmt->get_result()->fetch_assoc();
                $locStmt->close();
                if ($loc) {
                    $placeStmt = $conn->prepare("UPDATE patients SET city = ?, area = ?, location_id = ? WHERE id = ?");
                    $placeStmt->bind_param('ssii', $loc['city'], $loc['area'], $location_id, $final_patient_id);
                    $placeStmt->execute();
                    $placeStmt->close();
                }
            }

            $patientRow = $patientManager->getById($final_patient_id);
            if (!$patientRow) {
                throw new Exception('Patient lookup failed');
            }

            // Keep compatibility: still fill patient_name and mobile_number inside visits
            $patient_name = $patientRow['full_name'];
            $mobile_number = $patientRow['phone'];

            // Insert visit linked to patient_id (created_by = logged-in user, from session)
            $visit_created_by = (int)$currentUser['id'];
            $visitStmt = $conn->prepare("INSERT INTO visits (patient_id, patient_name, mobile_number, treated, treated_reason, requested_doctor_id, assigned_doctor_id, assigned_doctor_reason, arrival_time, checkin_time, notification_time, procedure_treatment, work_type_id, visit_type, price, patient_status, receptionist_id, referral_source, referral_source_notes, appointment_date, appointment_added_by, appointment_doctor_id, referring_doctor_id, referring_doctor_designation, referring_doctor_choice_id, appointment_image, notes, visit_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if (!$visitStmt) {
                throw new Exception('Failed to prepare visit insert');
            }

            $visitStmt->bind_param("issssiisssssisdsisssiiisisssi", $final_patient_id, $patient_name, $mobile_number, $treated, $treated_reason, $requested_doctor_id, $assigned_doctor_id, $assigned_doctor_reason, $arrival_time, $checkin_time, $notification_time, $procedure_treatment, $work_type_id, $visit_type, $visit_price, $patient_status, $receptionist_id, $referral_source, $referral_source_notes, $appointment_date, $appointment_added_by, $appointment_doctor_id, $referring_doctor_id, $referring_doctor_designation, $referring_doctor_choice_id, $appointment_image, $notes, $visit_date, $visit_created_by);

            if (!$visitStmt->execute()) {
                $visitStmt->close();
                throw new Exception('Failed to insert visit');
            }
            $new_visit_id = $conn->insert_id;
            $visitStmt->close();

            VisitDoctorAssignment::saveSource($conn, (int) $new_visit_id, $doctor_assignment_source);
            $conn->commit();

            AuditLog::log('create_visit', 'visits', $new_visit_id, null, [
                'patient_id'          => $final_patient_id,
                'patient_name'        => $patient_name,
                'requested_doctor_id' => $requested_doctor_id,
                'assigned_doctor_id'  => $assigned_doctor_id,
                'doctor_assignment_source' => $doctor_assignment_source,
                'visit_type'          => $visit_type,
                'price'               => $visit_price,
                'visit_date'          => $visit_date,
                'receptionist_id'     => $receptionist_id,
            ]);

            // Record the per-type doctor (دکتۆری فەحس / دکتۆری ئیش) on the new
            // visit. All ids are doctors.id. A treatment visit takes the doctor
            // from its own 'دکتۆری ئیش' box; a checkup takes the seen doctor,
            // falling back to the requested one.
            require_once __DIR__ . '/classes/VisitDoctorSync.php';
            $visit_doctor_id = $visit_type === 'treatment'
                ? (int)($treatment_doctor_id ?: $assigned_doctor_id ?: $requested_doctor_id ?: 0)
                : (int)($assigned_doctor_id ?: $requested_doctor_id ?: 0);
            VisitDoctorSync::assignToVisit(
                Database::getInstance(),
                (int)$new_visit_id,
                (int)$final_patient_id,
                $visit_type,
                $visit_doctor_id
            );

            // Resolve the visit's doctor → users.id (waiting_room.doctor_id references
            // users). Uses $visit_doctor_id so a treatment visit resolves its
            // 'دکتۆری ئیش' too — that is what preselects the doctor in the
            // post-save waiting-room modal and addresses the notification.
            // Prefer the explicit doctors.user_id link; name match is the legacy fallback.
            $doctor_user_id = null;
            if (!empty($visit_doctor_id)) {
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
                    $userLookupStmt->bind_param('ii', $visit_doctor_id, $visit_doctor_id);
                    if ($userLookupStmt->execute()) {
                        $userLookupRes = $userLookupStmt->get_result();
                        if ($userLookupRow = $userLookupRes->fetch_assoc()) {
                            $doctor_user_id = (int)$userLookupRow['id'];
                        }
                    }
                    $userLookupStmt->close();
                }
            }

            // Notify the assigned doctor about the new visit (fail-soft)
            if (!empty($doctor_user_id)) {
                try {
                    require_once __DIR__ . '/classes/Notification.php';
                    (new Notification())->notifyVisitCreated(
                        $doctor_user_id,
                        $patient_name,
                        $new_visit_id,
                        $final_patient_id,
                        (int)$currentUser['id']
                    );
                } catch (Exception $notifyError) {
                    error_log('new_visit notify failed: ' . $notifyError->getMessage());
                }
            }

            // "Save + send to waiting room" — combined action
            $send_to_waiting = !empty($_POST['send_to_waiting']);
            // Build redirect context for post-save dialogs.
                // Default flow: redirect back to new_visit.php with success → post-visit modal
            $redirect_qs = [
                'success' => 1,
                'patient_id' => $final_patient_id,
                'patient_name' => $patient_name,
                'visit_type' => $visit_type,
            ];
            if (!empty($work_type_id)) {
                $redirect_qs['work_type_id'] = (int)$work_type_id;
            }
            if (!empty($doctor_user_id)) {
                $redirect_qs['doctor_user_id'] = $doctor_user_id;
            }
            if ($send_to_waiting) {
                $redirect_qs['open_waiting'] = 1;
            }
            if ($embedLayout) {
                $redirect_qs['embed'] = 1;   // the choice dialog opens inside the same dialog
            }
            $redirect_url = APP_URL . "/new_visit.php?" . http_build_query($redirect_qs);
        } catch (Exception $e) {
            $conn->rollback();

            foreach ($uploaded_files as $path) {
                if (!empty($path) && file_exists($path)) {
                    @unlink($path);
                }
            }

            $error = 'هەڵە ڕوویدا لە تۆمارکردنی نەخۆش و سەردان';
        }
    } else {
        $error = 'تکایە خانە پێویستەکان پڕبکەرەوە';
    }
}

// If validation errors exist, show a consistent message
if (!empty($errors)) {
    $error = $errors['visit_required'] ?? 'تکایە خانە پێویستەکان پڕ بکەوە';
}

// Check for success message from redirect
$saved_patient_id = null;
$saved_patient_name = '';
$saved_visit_type = 'checkup';
$saved_work_type_id = null;
$saved_doctor_user_id = null;
$saved_open_waiting_modal = false;
if (isset($_GET['success'])) {
    $success = 'سەردانەکە بە سەرکەوتوویی تۆمار کرا';
    $saved_patient_id = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : null;
    $saved_patient_name = isset($_GET['patient_name']) ? urldecode($_GET['patient_name']) : '';
    $incomingVisitType = $_GET['visit_type'] ?? 'checkup';
    $saved_visit_type = in_array($incomingVisitType, ['checkup', 'treatment'], true) ? $incomingVisitType : 'checkup';
    $saved_work_type_id = !empty($_GET['work_type_id']) ? (int)$_GET['work_type_id'] : null;
    $saved_doctor_user_id = !empty($_GET['doctor_user_id']) ? (int)$_GET['doctor_user_id'] : null;
    $saved_open_waiting_modal = !empty($_GET['open_waiting']);
}

// Get doctors list for appointment modal — MUST use users table (same as appointments.php),
// because appointments.doctor_id and doctor_schedules.doctor_id both reference users.id.
$doctors_for_modal = $conn->query("SELECT id, full_name AS name FROM users WHERE role = 'doctor' AND is_active = 1 ORDER BY full_name");
// X-ray department accounts: selectable in the doctor box for x-ray-only patients
$xray_accounts_for_modal = $conn->query("SELECT id, full_name AS name FROM users WHERE role = 'xray_department' AND is_active = 1 ORDER BY full_name");

// Get work types for appointment modal (from work_types lookup table)
// (with each one's icon, and how many visits used it — the «جۆری ئیش» cards
// put the most used first)
$work_types_for_modal = $conn->query(
    "SELECT wt.id, wt.work_name, wt.icon, COALESCE(u.n, 0) AS uses FROM work_types wt
     LEFT JOIN (SELECT work_type_id, COUNT(*) AS n FROM visits WHERE work_type_id IS NOT NULL GROUP BY work_type_id) u
            ON u.work_type_id = wt.id
     WHERE wt.is_active = 1 AND " . work_type_scope_sql('new_visit', [], 'wt') . "
     ORDER BY wt.sort_order, wt.id"
);

// شوێن — پارێزگا → ئیدارە → شار → گەڕەک (api/locations.php, 2026_10_28_locations.sql)
$locations_all = [];
if ($locRes = @$conn->query("SELECT id, parent_id, level, name FROM locations WHERE is_active = 1 ORDER BY sort_order, name")) {
    while ($locRow = $locRes->fetch_assoc()) {
        $locations_all[] = ['id' => (int)$locRow['id'], 'parent_id' => $locRow['parent_id'] !== null ? (int)$locRow['parent_id'] : null,
                            'level' => $locRow['level'], 'name' => $locRow['name']];
    }
}

// Kurdish duration labels 15m → 12h (30-min steps after 1h)
function dh_duration_options_mysqli($selected = 30) {
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
?>

<?php if ($redirect_url): ?>
<script>window.location.href = '<?= $redirect_url ?>';</script>
<?php endif; ?>

<style>
/* Compact Design - Similar to folders/index.php */
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

/* Sub-section label inside a merged form-card — hidden by request, kept in markup for future use */
.subsection-label {
    display: none;
}

/* Green separator between sub-sections within a merged card */
.green-separator {
    border: 0;
    border-top: 2px solid #4caf50;
    margin: 18px 0 14px;
    opacity: .55;
}

/* Icon Colors */
.icon-blue { background: linear-gradient(135deg, var(--win-accent, #0078d4), var(--win-accent-deep, #0063b1)); }
.icon-green { background: linear-gradient(135deg, #4caf50, #388e3c); }
.icon-purple { background: linear-gradient(135deg, #9c27b0, #7b1fa2); }
.icon-orange { background: linear-gradient(135deg, #ff9800, #f57c00); }
.icon-teal { background: linear-gradient(135deg, #00bcd4, #0097a7); }
.icon-pink { background: linear-gradient(135deg, #e91e63, #c2185b); }
.icon-indigo { background: linear-gradient(135deg, #3f51b5, #303f9f); }

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

/* Compact Labels */
.form-group label {
    display: block;
    font-size: 11px;
    color: #000;
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
    border-color: #4caf50;
    outline: none;
    box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
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
    background: linear-gradient(135deg, #f8fffa 0%, #ffffff 100%);
    border: 1.5px solid #e8f5e9;
    border-radius: 14px;
    padding: 14px 18px;
    margin-bottom: 14px;
    box-shadow: 0 4px 16px rgba(76, 175, 80, .08);
}

.search-row {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
}

.search-input-wrapper {
    flex: 1;
    min-width: 280px;
    position: relative;
}

.search-input-wrapper::before {
    content: '\f002';
    font-family: 'Font Awesome 6 Free', 'Font Awesome 5 Free', 'FontAwesome';
    font-weight: 900;
    position: absolute;
    right: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: #4caf50;
    font-size: 15px;
    pointer-events: none;
    z-index: 2;
}

.search-input-wrapper input {
    width: 100%;
    padding: 12px 42px 12px 14px;
    border: 1.5px solid #c8e6c9;
    border-radius: 10px;
    font-size: 14px;
    background: white;
    transition: border-color .2s ease, box-shadow .2s ease;
}

.search-input-wrapper input::placeholder {
    color: #9aa7b3;
    font-size: 13px;
}

.search-input-wrapper input:focus {
    border-color: #4caf50;
    box-shadow: 0 0 0 3px rgba(76, 175, 80, .15);
    outline: none;
}

.search-results {
    position: absolute;
    top: calc(100% + 6px);
    right: 0;
    left: 0;
    background: white;
    border: 1px solid #e8f5e9;
    border-radius: 12px;
    box-shadow: 0 10px 30px rgba(0,0,0,.15);
    max-height: 320px;
    overflow-y: auto;
    z-index: 1000;
    display: none;
    animation: nvSearchFadeIn .15s ease-out;
}

@keyframes nvSearchFadeIn {
    from { opacity: 0; transform: translateY(-4px); }
    to   { opacity: 1; transform: translateY(0); }
}

.search-item {
    padding: 11px 16px;
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    gap: 12px;
    align-items: center;
    border-bottom: 1px solid #f3f4f6;
    font-size: 13px;
    transition: background .12s ease;
}

.search-item:last-child { border-bottom: none; }
.search-item:hover {
    background: linear-gradient(90deg, #e8f5e9 0%, #f1f8f4 100%);
}
.search-item .name { font-weight: 700; color: #1f2937; }
.search-item .phone {
    color: #455a64;
    background: #f1f5f9;
    padding: 3px 9px;
    border-radius: 8px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .3px;
}

.btn-search {
    padding: 10px 16px;
    background: linear-gradient(135deg, #4caf50, #388e3c);
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
    background: linear-gradient(135deg, #388e3c, #2e7d32);
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
    border-color: #4caf50;
    background: #f5f5f5;
}

.radio-label input[type="radio"] {
    width: 14px;
    height: 14px;
    accent-color: #4caf50;
}

.radio-label input[type="radio"]:checked + span {
    color: #4caf50;
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

/* Form Actions */
.form-actions {
    display: flex;
    gap: 12px;
    justify-content: center;
    padding: 16px;
    background: white;
    border-radius: 10px;
    margin-top: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
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

.form-actions .btn-secondary {
    background: #f5f5f5;
    color: #666;
    border: 1.5px solid #e0e0e0;
}

.form-actions .btn-secondary:hover {
    background: #e0e0e0;
}

/* Combined "save + send to waiting" submit button */
.form-actions .btn-save-send {
    background: linear-gradient(135deg, var(--win-accent, #0078d4), var(--win-accent-dark, #005a9e));
    box-shadow: 0 3px 10px rgba(var(--win-accent-rgb, 0, 120, 212), 0.3);
}
.form-actions .btn-save-send:hover {
    background: linear-gradient(135deg, var(--win-accent-dark, #005a9e), #004578);
}

/* Profile Preview */
.profile-preview {
    margin-top: 4px;
}

.profile-preview a {
    color: var(--win-accent, #0078d4);
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

<?php if ($success && !$saved_patient_id): ?>
<div class="alert alert-success" data-auto-hide>
    <i class="fas fa-check-circle alert-icon"></i>
    <?php echo $success; ?>
</div>
<?php endif; ?>

<!-- Post Visit Choice Dialog -->
<div id="postVisitChoiceModal" class="dialog-overlay">
    <div class="dialog post-visit-dialog">
        <div class="post-visit-header">
            <div class="post-visit-icon"><i class="fas fa-check"></i></div>
            <div>
                <h4>سەردان تۆمارکرا</h4>
                <p>نەخۆش: <strong id="post-visit-patient-name"></strong></p>
            </div>
            <button type="button" class="post-visit-close" onclick="closePostVisitChoiceModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="post-visit-body">
            <button type="button" class="post-visit-action post-visit-appointment" onclick="choosePostVisitAppointment()">
                <span class="post-visit-action-icon"><i class="fas fa-calendar-plus"></i></span>
                <span>
                    <strong>مەوعید دانان</strong>
                    <small>بۆ ئەم نەخۆشە مەوعیدی نوێ تۆمار بکە</small>
                </span>
            </button>
            <button type="button" class="post-visit-action post-visit-waiting" onclick="choosePostVisitWaitingRoom()">
                <span class="post-visit-action-icon"><i class="fas fa-user-clock"></i></span>
                <span>
                    <strong>ناردن بۆ ژووری چاوەڕوانی</strong>
                    <small>نەخۆش بخە ژووری چاوەڕوانی بۆ بینین</small>
                </span>
            </button>
        </div>
    </div>
</div>

<!-- Appointment Modal — same look & feel as appointments.php new-appointment dialog -->
<div id="appointmentModal" class="dialog-overlay">
    <div class="dialog apt-dialog-modern">
        <div class="apt-dialog-header">
            <h4><i class="fas fa-calendar-plus"></i> مەوعیدی نوێ بۆ <span id="modal-patient-name"></span></h4>
            <button type="button" class="apt-dialog-close" onclick="closeAppointmentModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="apt-dialog-body">
            <input type="hidden" id="apt_patient_id">

            <!-- Row 1: Doctor, Date, Duration -->
            <div class="apt-form-grid">
                <div class="apt-form-field">
                    <label><i class="fas fa-user-md"></i> دکتۆر <span class="required">*</span></label>
                    <select id="apt_doctor_id" required onchange="refreshAptSlots()">
                        <option value="">هەڵبژاردن...</option>
                        <?php if ($doctors_for_modal): while ($doc = $doctors_for_modal->fetch_assoc()): ?>
                            <option value="<?= $doc['id'] ?>"><?= htmlspecialchars($doc['name']) ?></option>
                        <?php endwhile; endif; ?>
                    </select>
                </div>
                <div class="apt-form-field">
                    <label><i class="fas fa-calendar"></i> بەروار <span class="required">*</span></label>
                    <input type="date" id="apt_date" value="<?= date('Y-m-d') ?>" required onchange="refreshAptSlots()">
                </div>
                <div class="apt-form-field">
                    <label><i class="fas fa-hourglass-half"></i> ماوە</label>
                    <select id="apt_duration" onchange="refreshAptSlots()">
                        <?= dh_duration_options_mysqli(30) ?>
                    </select>
                </div>
            </div>

            <!-- Row 2: Type, Work type (3-col grid with + button) -->
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
                    <div style="display: flex; gap: 4px;">
                        <select id="apt_work_type" style="flex: 1;">
                            <option value="">— هەڵبژێرە —</option>
                            <?php if ($work_types_for_modal): while ($wt = $work_types_for_modal->fetch_assoc()): ?>
                                <option value="<?= $wt['id'] ?>"><?= htmlspecialchars($wt['work_name']) ?></option>
                            <?php endwhile; endif; ?>
                        </select>
                        <button type="button" onclick="openAddWorkTypeNv()" title="زیادکردنی ئیشی نوێ"
                                style="padding: 0 10px; background: var(--win-accent, #0078d4); color: #fff; border: 1px solid var(--win-accent, #0078d4); border-radius: 4px; cursor: pointer;">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Existing Bookings -->
            <div class="existing-bookings-section" id="apt_existing_section" style="display: none;">
                <label><i class="fas fa-calendar-check"></i> گیراوەکان</label>
                <div id="apt_existing_list" class="existing-bookings-list"></div>
            </div>

            <!-- Time Picker Section -->
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

            <!-- Booking Summary -->
            <div class="booking-summary-apt" id="apt_summary" style="display: none;">
                <div class="summary-title"><i class="fas fa-info-circle"></i> پوختە</div>
                <div class="summary-grid">
                    <div class="summary-item">
                        <span class="summary-label">بەروار</span>
                        <span class="summary-value" id="apt_sum_date">-</span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">دەستپێک</span>
                        <span class="summary-value" id="apt_sum_start">-</span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">کۆتایی</span>
                        <span class="summary-value" id="apt_sum_end">-</span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">ماوە</span>
                        <span class="summary-value" id="apt_sum_duration">-</span>
                    </div>
                </div>
            </div>

            <!-- Collapsible Notes -->
            <div class="apt-advanced-toggle" id="apt_notes_toggle" onclick="toggleAptNotes()">
                <span><i class="fas fa-sticky-note"></i> تێبینی</span>
                <i class="fas fa-chevron-down apt-toggle-icon"></i>
            </div>
            <div class="apt-advanced-options" id="apt_notes_section">
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

<!-- Add Work Type Dialog -->
<div id="addWorkTypeDialogNv" class="dialog-overlay">
    <div class="dialog apt-dialog-modern" style="max-width: 540px;">
        <div class="apt-dialog-header" style="background: linear-gradient(135deg, var(--win-accent, #0078d4) 0%, var(--win-accent-dark, #005a9e) 100%);">
            <h4><i class="fas fa-briefcase-medical"></i> زیادکردنی ئیشی نوێ</h4>
            <button type="button" class="apt-dialog-close" onclick="closeAddWorkTypeNv()"><i class="fas fa-times"></i></button>
        </div>
        <div class="apt-dialog-body">
            <div class="apt-form-field">
                <label><i class="fas fa-tag"></i> ناوی ئیش <span class="required">*</span></label>
                <input type="text" id="newWorkTypeNameNv" placeholder="بۆ نموونە: ددانی شکاوە" autocomplete="off">
            </div>
            <!-- one click fills the name and the icon -->
            <div class="wt-examples" id="wtExamples"><span>نموونە:</span></div>
            <div class="apt-form-field" style="margin-top:10px;">
                <label><i class="fas fa-icons"></i> ئایکۆن <span class="required">*</span></label>
                <div id="newWorkTypeIconsNv"></div>
            </div>
            <div id="addWorkTypeErrNv" style="color:#f44336;font-size:12px;margin-top:6px;display:none;"></div>
        </div>
        <div class="apt-dialog-footer">
            <button type="button" class="apt-btn-primary" onclick="submitNewWorkTypeNv()"><i class="fas fa-check"></i> زیادکردن</button>
            <button type="button" class="apt-btn-secondary" onclick="closeAddWorkTypeNv()"><i class="fas fa-times"></i> هەڵوەشاندنەوە</button>
        </div>
    </div>
</div>

<!-- Add a place: پارێزگا، (ئیدارە)، شار، گەڕەک — each picked from what exists or typed new -->
<div id="addCityDialog" class="dialog-overlay">
    <div class="dialog apt-dialog-modern" style="max-width: 460px;">
        <div class="apt-dialog-header" style="background: linear-gradient(135deg, var(--win-accent, #0078d4) 0%, var(--win-accent-dark, #005a9e) 100%);">
            <h4><i class="fas fa-map-marker-alt"></i> زیادکردنی شوێنی نوێ</h4>
            <button type="button" class="apt-dialog-close" onclick="closeAddCityDialog()"><i class="fas fa-times"></i></button>
        </div>
        <div class="apt-dialog-body">
            <div class="apt-form-field">
                <label><i class="fas fa-map"></i> پارێزگا <span class="required">*</span></label>
                <input type="text" id="newPlaceProvince" list="locProvinceList" placeholder="بۆ نموونە: هەولێر" autocomplete="off">
            </div>
            <div class="apt-form-field" style="margin-top:10px;">
                <label><i class="fas fa-landmark"></i> ئیدارە <small style="color:#90a4ae;font-weight:400;">(ئەگەر هەبێت)</small></label>
                <input type="text" id="newPlaceRegion" list="locRegionList" placeholder="بۆ نموونە: ئیدارەی سەربەخۆی سۆران" autocomplete="off">
            </div>
            <div class="apt-form-field" style="margin-top:10px;">
                <label><i class="fas fa-city"></i> شار / قەزا <span class="required">*</span></label>
                <input type="text" id="newPlaceCity" list="locCityList" placeholder="بۆ نموونە: سۆران" autocomplete="off">
            </div>
            <div class="apt-form-field" style="margin-top:10px;">
                <label><i class="fas fa-house-chimney"></i> گەڕەک / گوند <span class="required">*</span></label>
                <input type="text" id="newPlaceArea" list="locAreaList" placeholder="بۆ نموونە: ئازادی" autocomplete="off">
            </div>
            <datalist id="locProvinceList"></datalist><datalist id="locRegionList"></datalist>
            <datalist id="locCityList"></datalist><datalist id="locAreaList"></datalist>
            <div id="addCityErr" style="color:#f44336;font-size:12px;margin-top:8px;display:none;"></div>
        </div>
        <div class="apt-dialog-footer">
            <button type="button" class="apt-btn-primary" onclick="submitNewCity()"><i class="fas fa-check"></i> زیادکردن</button>
            <button type="button" class="apt-btn-secondary" onclick="closeAddCityDialog()"><i class="fas fa-times"></i> هەڵوەشاندنەوە</button>
        </div>
    </div>
</div>

<!-- Send to Waiting Room Dialog -->
<div id="newVisitWaitingModal" class="waiting-modal">
    <form class="waiting-modal-content" onsubmit="return submitNewVisitWaitingRoom();">
        <input type="hidden" id="nv_wr_patient_id">
        <input type="hidden" id="nv_wr_visit_type" value="checkup">

        <div class="waiting-modal-header">
            <div class="waiting-modal-icon"><i class="fas fa-user-clock"></i></div>
            <h3>ناردن بۆ ژووری چاوەڕوانی</h3>
            <p>نەخۆش: <strong id="nv_wr_patient_name"></strong></p>
        </div>

        <div class="waiting-modal-body">
            <div class="waiting-form-group">
                <label><i class="fas fa-user-md"></i> دکتۆر <span style="color:#f44336;">*</span></label>
                <select id="nv_wr_doctor_id" required
                        onchange="if (this.selectedOptions[0] && this.selectedOptions[0].dataset.xray) { document.getElementById('nv_wr_visit_type').value = 'checkup'; }">
                    <option value="">— دکتۆر هەڵبژێرە —</option>
                    <?php if ($doctors_for_modal): $doctors_for_modal->data_seek(0); while ($doc = $doctors_for_modal->fetch_assoc()): ?>
                        <option value="<?= $doc['id'] ?>"><?= htmlspecialchars($doc['name']) ?></option>
                    <?php endwhile; endif; ?>
                    <?php if ($xray_accounts_for_modal): $xray_accounts_for_modal->data_seek(0); while ($xa = $xray_accounts_for_modal->fetch_assoc()): ?>
                        <option value="<?= $xa['id'] ?>" data-xray="1">ئێکسڕەی — <?= htmlspecialchars($xa['name']) ?></option>
                    <?php endwhile; endif; ?>
                </select>
            </div>

            <div class="waiting-form-group">
                <label><i class="fas fa-briefcase-medical"></i> جۆری ئیش</label>
                <div style="display: flex; gap: 4px;">
                    <select id="nv_wr_work_type_id" style="flex: 1;">
                        <option value="">— هەڵبژێرە —</option>
                        <?php if ($work_types_for_modal): $work_types_for_modal->data_seek(0); while ($wt = $work_types_for_modal->fetch_assoc()): ?>
                            <option value="<?= $wt['id'] ?>"><?= htmlspecialchars($wt['work_name']) ?></option>
                        <?php endwhile; endif; ?>
                    </select>
                    <button type="button" onclick="openAddWorkTypeNv()" title="زیادکردنی ئیشی نوێ"
                            style="padding: 0 10px; background: var(--win-accent, #0078d4); color: #fff; border: 1px solid var(--win-accent, #0078d4); border-radius: 4px; cursor: pointer;">
                        <i class="fas fa-plus"></i>
                    </button>
                </div>
            </div>

            <div class="waiting-form-group">
                <label><i class="fas fa-flag"></i> پێشینە</label>
                <div class="priority-options">
                    <label class="priority-option">
                        <input type="radio" name="nv_wr_priority" value="0" checked>
                        <span class="priority-label normal">ئاسایی</span>
                    </label>
                    <label class="priority-option">
                        <input type="radio" name="nv_wr_priority" value="1">
                        <span class="priority-label important">گرنگ</span>
                    </label>
                    <label class="priority-option">
                        <input type="radio" name="nv_wr_priority" value="2">
                        <span class="priority-label urgent">پەلە</span>
                    </label>
                </div>
            </div>

            <div class="waiting-form-group">
                <label><i class="fas fa-sticky-note"></i> تێبینی</label>
                <textarea id="nv_wr_notes" rows="2" placeholder="تێبینی..."></textarea>
            </div>
        </div>

        <div class="waiting-modal-footer">
            <button type="button" class="btn-skip" onclick="closeNewVisitWaitingModal()">
                <i class="fas fa-times"></i> هەڵوەشاندنەوە
            </button>
            <button type="submit" class="btn-add-waiting" id="nv_wr_submit_btn">
                <i class="fas fa-check"></i> ناردن
            </button>
        </div>
    </form>
</div>

<style>
/* ===== Appointment Dialog — mirrors appointments.php exactly ===== */
.dialog-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,.55); z-index: 9999;
    display: flex; align-items: center; justify-content: center; padding: 16px;
}
.post-visit-dialog {
    max-width: 520px;
    width: 96%;
    background: #fff;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 20px 60px rgba(0,0,0,.25);
}
.post-visit-header {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 18px 20px;
    border-bottom: 1px solid #eef1f4;
    background: linear-gradient(135deg, #f2fbf4, #ffffff);
}
.post-visit-icon {
    width: 46px;
    height: 46px;
    border-radius: 14px;
    background: linear-gradient(135deg, #4caf50, #2e7d32);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
}
.post-visit-header h4 {
    margin: 0 0 4px;
    font-size: 17px;
    color: #1b1b1b;
}
.post-visit-header p {
    margin: 0;
    font-size: 13px;
    color: #667085;
}
.post-visit-header strong { color: #2e7d32; }
.post-visit-close {
    margin-right: auto;
    border: none;
    background: #f3f4f6;
    color: #667085;
    width: 32px;
    height: 32px;
    border-radius: 8px;
    cursor: pointer;
}
.post-visit-body {
    padding: 18px 20px 20px;
    display: grid;
    gap: 12px;
}
.post-visit-action {
    width: 100%;
    border: 1.5px solid #e4e7ec;
    border-radius: 12px;
    background: #fff;
    padding: 14px;
    display: flex;
    align-items: center;
    gap: 12px;
    cursor: pointer;
    text-align: right;
    font-family: inherit;
    transition: all .2s;
}
.post-visit-action:hover {
    transform: translateY(-1px);
    box-shadow: 0 10px 24px rgba(15, 23, 42, .08);
}
.post-visit-action-icon {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 18px;
    flex-shrink: 0;
}
.post-visit-appointment .post-visit-action-icon { background: linear-gradient(135deg, var(--win-accent, #0078d4), var(--win-accent-dark, #005a9e)); }
.post-visit-waiting .post-visit-action-icon { background: linear-gradient(135deg, #00a884, #007a63); }
.post-visit-action strong {
    display: block;
    font-size: 14px;
    color: #1f2937;
    margin-bottom: 3px;
}
.post-visit-action small {
    display: block;
    font-size: 12px;
    color: #667085;
}
.dialog.apt-dialog-modern {
    max-width: 580px; width: 96%; border-radius: 12px; overflow: hidden;
    box-shadow: 0 20px 60px rgba(0,0,0,.3); background: #fff;
    max-height: 92vh; max-height: 92dvh; display: flex; flex-direction: column;
}
/* Pass flex layout through a wrapping <form> so the body scrolls and the footer stays visible */
.dialog.apt-dialog-modern > form { display: flex; flex-direction: column; flex: 1; min-height: 0; }
.apt-dialog-header {
    background: linear-gradient(135deg, var(--win-accent, #0078d4) 0%, var(--win-accent-deep, #0063b1) 100%);
    color: #fff; padding: 12px 16px;
    display: flex; justify-content: space-between; align-items: center; flex-shrink: 0;
}
.apt-dialog-header h4 { margin: 0; font-size: 15px; font-weight: 600; display: flex; align-items: center; gap: 8px; }
.apt-dialog-close {
    background: rgba(255,255,255,.15); border: none; color: #fff;
    width: 28px; height: 28px; border-radius: 6px; cursor: pointer;
    display: flex; align-items: center; justify-content: center; font-size: 12px; transition: all .2s;
}
.apt-dialog-close:hover { background: rgba(255,255,255,.25); }
.apt-dialog-body { padding: 14px 16px; overflow-y: auto; flex: 1; min-height: 0; }

.apt-form-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
.apt-form-field { display: flex; flex-direction: column; gap: 4px; }
.apt-form-field.apt-field-full { grid-column: 1 / -1; }
.apt-form-field.apt-field-2col { grid-column: span 2; }
.apt-form-field label { font-size: 11px; font-weight: 600; color: #546e7a; display: flex; align-items: center; gap: 5px; }
.apt-form-field label i { color: var(--win-accent, #0078d4); font-size: 10px; }
.apt-form-field label .required { color: #f44336; font-size: 10px; }
.apt-form-field input, .apt-form-field select {
    padding: 8px 10px; border: 1.5px solid #e0e0e0; border-radius: 6px;
    font-size: 12px; font-family: inherit; transition: all .2s; background: #fafafa;
}
.apt-form-field input:focus, .apt-form-field select:focus {
    border-color: var(--win-accent, #0078d4); box-shadow: 0 0 0 2px rgba(var(--win-accent-rgb, 0, 120, 212), .1); outline: none; background: #fff;
}

/* Smart Time Picker */
.apt-time-section { margin-top: 16px; padding-top: 16px; border-top: 1px solid #f0f0f0; }
.apt-time-section > label { font-size: 11px; font-weight: 600; color: #546e7a; display: flex; align-items: center; gap: 5px; margin-bottom: 6px; }
.apt-time-section > label i { color: var(--win-accent, #0078d4); font-size: 10px; }
.apt-time-section > label .required { color: #f44336; font-size: 10px; }
.apt-time-grid-container {
    background: #f8f9fa; border: 1px solid #e0e0e0; border-radius: 6px;
    padding: 8px; max-height: 200px; overflow-y: auto;
}
.apt-time-loading { text-align: center; padding: 15px; color: #757575; font-size: 12px; }
.smart-time-picker-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(65px, 1fr));
    gap: 6px; padding: 4px;
}
.smart-time-slot {
    padding: 8px 4px; border: 1.5px solid #e0e0e0; border-radius: 6px;
    text-align: center; cursor: pointer; transition: all .2s;
    font-size: 12px; font-weight: 600; background: #fff; color: #37474f;
}
.smart-time-slot:hover:not(.busy):not(.break) {
    border-color: var(--win-accent, #0078d4); background: #e3f2fd; color: var(--win-accent, #0078d4); transform: translateY(-1px);
}
.smart-time-slot.selected { background: var(--win-accent, #0078d4); color: #fff; border-color: var(--win-accent, #0078d4); }
.smart-time-slot.busy { background: #ffebee; border-color: #ffcdd2; color: #c62828; cursor: not-allowed; opacity: .7; }
/* Booked slots stay selectable (overlapping bookings allowed) */
.smart-time-slot.booked { background: #ffebee; border-color: #ffcdd2; color: #c62828; cursor: pointer; }
.smart-time-slot.booked.selected { background: var(--win-accent, #0078d4); color: #fff; border-color: var(--win-accent, #0078d4); }
.smart-time-slot.break { background: #fff3e0; border-color: #ffe0b2; color: #e65100; cursor: not-allowed; opacity: .7; }
.apt-time-legend { display: flex; gap: 12px; margin-top: 6px; font-size: 10px; color: #78909c; }
.apt-time-legend span { display: flex; align-items: center; gap: 3px; }

/* ── City searchable combo ── */
.city-combo { position: relative; flex: 1; }
.city-combo input[type="text"] { width: 100%; }
.city-combo-dropdown {
    display: none; position: absolute; top: 100%; right: 0; left: 0; z-index: 300;
    background: #fff; border: 1px solid #cfd8dc; border-radius: 6px; margin-top: 2px;
    max-height: 240px; overflow-y: auto; box-shadow: 0 8px 24px rgba(0,0,0,.12);
}
.city-combo-group {
    padding: 6px 10px; background: #f7f9fc; color: #607d8b;
    font-size: 11px; font-weight: 700; position: sticky; top: 0;
}
.city-combo-item { padding: 8px 14px; cursor: pointer; font-size: 13px; color: #37474f; }
.city-combo-item:hover { background: #e3f2fd; color: var(--win-accent, #0078d4); }
.city-combo-empty { padding: 12px 14px; color: #9aa7b3; font-size: 12px; text-align: center; }
.apt-time-legend .legend-available i { color: #4caf50; font-size: 6px; }
.apt-time-legend .legend-busy i { color: #f44336; font-size: 6px; }
.apt-time-legend .legend-break i { color: #ff9800; font-size: 6px; }
.no-working-day { text-align: center; padding: 20px; color: #f44336; background: #ffebee; border-radius: 8px; font-size: 13px; }
.no-working-day i { font-size: 24px; display: block; margin-bottom: 8px; }

/* Existing Bookings */
.existing-bookings-section {
    margin: 10px 0; padding: 8px 10px;
    background: linear-gradient(135deg, #fff8e1, #fffde7);
    border-radius: 6px; border: 1px solid #ffe082;
}
.existing-bookings-section label { display: block; font-weight: 600; font-size: 10px; color: #ef6c00; margin-bottom: 6px; }
.existing-bookings-list { display: flex; flex-wrap: wrap; gap: 6px; }
.existing-booking-item {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 5px 10px; background: #fff; border: 1px solid #ffcc80;
    border-radius: 16px; font-size: 11px; color: #e65100;
}
.existing-booking-item i { font-size: 9px; }
.existing-booking-item .patient-name { font-weight: 600; color: #bf360c; max-width: 80px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.no-bookings-message { padding: 8px; text-align: center; color: #7cb342; font-size: 12px; }

/* Booking Summary */
.booking-summary-apt {
    margin-top: 10px; padding: 8px 12px;
    background: linear-gradient(135deg, #e8f4fd 0%, #d4e9fc 100%);
    border-radius: 6px; border: 1px solid #90caf9;
}
.booking-summary-apt .summary-title { font-weight: 600; font-size: 10px; color: #1976d2; margin-bottom: 6px; display: flex; align-items: center; gap: 4px; }
.booking-summary-apt .summary-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; }
.booking-summary-apt .summary-item { display: flex; flex-direction: column; gap: 2px; background: rgba(255,255,255,.6); padding: 4px 6px; border-radius: 4px; }
.booking-summary-apt .summary-label { font-size: 9px; color: #1976d2; font-weight: 600; }
.booking-summary-apt .summary-value { font-size: 11px; color: #0d47a1; font-weight: 700; }

/* Advanced / notes toggle */
.apt-advanced-toggle {
    display: flex; justify-content: space-between; align-items: center;
    padding: 8px 0; margin-top: 10px; cursor: pointer; color: #78909c;
    font-size: 11px; font-weight: 600; border-top: 1px dashed #e0e0e0; transition: color .2s;
}
.apt-advanced-toggle:hover { color: var(--win-accent, #0078d4); }
.apt-advanced-toggle span { display: flex; align-items: center; gap: 5px; }
.apt-toggle-icon { transition: transform .3s; font-size: 10px; }
.apt-advanced-toggle.open .apt-toggle-icon { transform: rotate(180deg); }
.apt-advanced-options { display: none; padding-top: 10px; }
.apt-advanced-options.open { display: block; }

/* Footer */
.apt-dialog-footer {
    padding: 10px 16px; background: #f5f7f9; border-top: 1px solid #e0e0e0;
    display: flex; gap: 8px; flex-direction: row-reverse; flex-shrink: 0;
}
.apt-btn-primary, .apt-btn-secondary {
    padding: 8px 16px; border-radius: 6px; font-size: 12px; font-weight: 600;
    cursor: pointer; display: flex; align-items: center; gap: 5px; transition: all .2s;
    border: none; font-family: inherit;
}
.apt-btn-primary { background: linear-gradient(135deg, var(--win-accent, #0078d4) 0%, var(--win-accent-deep, #0063b1) 100%); color: #fff; }
.apt-btn-primary:hover { background: linear-gradient(135deg, var(--win-accent-deep, #0063b1) 0%, #004c8c 100%); }
.apt-btn-secondary { background: #fff; color: #546e7a; border: 1.5px solid #e0e0e0; }
.apt-btn-secondary:hover { background: #f5f5f5; }

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
    background: linear-gradient(135deg, #4caf50, #388e3c);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 16px;
    box-shadow: 0 4px 15px rgba(76, 175, 80, 0.3);
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
    color: #4caf50;
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
    border-color: #4caf50;
    outline: none;
    box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
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
    background: linear-gradient(135deg, #4caf50, #388e3c);
    color: white;
    box-shadow: 0 3px 10px rgba(76, 175, 80, 0.3);
}

.btn-add-waiting:hover {
    background: linear-gradient(135deg, #388e3c, #2e7d32);
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

<?php if ($error): ?>
<div class="alert alert-error" data-auto-hide>
    <i class="fas fa-exclamation-circle alert-icon"></i>
    <?php echo $error; ?>
</div>
<?php endif; ?>

<div class="report-container">
<style id="nv-redesign-v2">
/* ══════════ Reception speed redesign — overrides ══════════ */

/* Numbered steps on the cards so the receptionist always knows where they are */
form[enctype="multipart/form-data"] { counter-reset: nvstep; }
form[enctype="multipart/form-data"] .form-card > .form-card-header h3::before {
    counter-increment: nvstep;
    content: counter(nvstep, arabic-indic);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    margin-left: 8px;
    background: #2e7d32;
    color: #fff;
    border-radius: 50%;
    font-size: 12px;
    font-weight: 800;
    vertical-align: middle;
}

/* Bigger, calmer inputs — easier to hit, easier to read */
.form-group input,
.form-group select,
.form-group textarea {
    padding: 10px 12px;
    font-size: 14px;
    border-radius: 8px;
    border-color: #dde3e8;
    background: #fbfcfd;
    min-height: 42px;
}
.form-group textarea { min-height: 56px; }
.form-group input:hover,
.form-group select:hover,
.form-group textarea:hover { border-color: #9ccc9f; }
.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    background: #fff;
    border-color: #2e7d32;
    box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.15);
}
.form-group label { font-size: 12px; color: #33413a; }
.form-group:focus-within > label { color: #2e7d32; }
.form-group input.error, .form-group select.error {
    border-color: #f44336;
    background: #fff5f5;
    box-shadow: 0 0 0 3px rgba(244, 67, 54, .12);
}

/* Hero patient search — the entry point of the whole flow */
.search-card {
    border: 2px solid #a5d6a7;
    box-shadow: 0 6px 22px rgba(46, 125, 50, .12);
    position: relative;
}
.search-input-wrapper input {
    padding: 15px 46px 15px 14px;
    font-size: 16px;
    border-radius: 12px;
    font-weight: 600;
}
.search-input-wrapper input::placeholder { font-size: 14px; font-weight: 400; }
.nv-search-hint {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    color: #607d6b;
    white-space: nowrap;
}
.nv-search-hint kbd,
.form-actions kbd {
    background: #eef3ef;
    border: 1px solid #cfd8d1;
    border-bottom-width: 2px;
    border-radius: 5px;
    padding: 1px 7px;
    font-size: 11px;
    font-family: inherit;
    color: #2e5138;
}
#clear_patient_selection {
    background: #fff3e0;
    color: #e65100;
    border-color: #ffb74d;
    font-weight: 700;
}

/* Segmented choice buttons (visit type, patient status, treated) */
.radio-label {
    padding: 10px 18px;
    font-size: 13px;
    border-radius: 8px;
    background: #fbfcfd;
    user-select: none;
    min-height: 42px;
}
.radio-label:has(input:checked) {
    background: #e8f5e9;
    border-color: #2e7d32;
    box-shadow: inset 0 0 0 1px #2e7d32;
}
.radio-label:has(input:checked) span { color: #1b5e20; font-weight: 800; }

/* Medical-condition chips */
.condition-card { border-radius: 8px; padding: 10px 12px; }
.condition-header label { font-size: 12px; }
.condition-card.active { box-shadow: inset 0 0 0 1px #e91e63; }

/* One-tap "now" buttons on time fields */
.nv-label-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 6px;
}
.nv-now-btn {
    border: 1px solid #c8e6c9;
    background: #f1f8f4;
    color: #2e7d32;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 700;
    padding: 2px 9px;
    cursor: pointer;
    font-family: inherit;
    line-height: 1.6;
}
.nv-now-btn:hover { background: #2e7d32; color: #fff; border-color: #2e7d32; }

/* Sticky action bar — Save is always one glance away, no scrolling */
.form-actions {
    position: sticky;
    bottom: 10px;
    z-index: 60;
    border: 1px solid #e3e9e4;
    box-shadow: 0 -4px 24px rgba(0, 0, 0, .10), 0 4px 14px rgba(0, 0, 0, .08);
    padding: 12px 16px;
    flex-wrap: wrap;
}
.form-actions .btn { padding: 13px 26px; font-size: 15px; border-radius: 10px; }
.form-actions .btn small {
    display: block;
    font-size: 10px;
    font-weight: 400;
    opacity: .85;
    margin-top: 1px;
}
.form-actions .btn:active { transform: translateY(1px); }

/* Slightly tighter vertical rhythm = less scrolling */
.form-card { margin-bottom: 10px; }
.form-card-body { padding: 12px 16px 14px; }
.green-separator { margin: 14px 0 12px; }

/* Two-line buttons (label + shortcut) need their height, not the app's fixed one. */
.form-actions .btn { height: auto; min-height: 46px; line-height: 1.35; }

/* ── Two columns: the patient (right) and the visit (left) ── */
.nv-layout { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 12px; align-items: start; }
.nv-col { min-width: 0; }
.nv-layout .form-grid,
.nv-layout .form-grid-3 { grid-template-columns: repeat(auto-fill, minmax(165px, 1fr)); }
.nv-layout .form-group.span-4,
.nv-layout .form-group.span-2,
.nv-layout .form-group.span-3 { grid-column: 1 / -1; }
.nv-layout .form-group.nv-photo { grid-column: auto; }
/* the full name is the longest thing typed here: two columns wide */
.nv-layout .form-group.nv-name { grid-column: span 2; }
.nv-layout .form-group.nv-place { grid-column: span 2; }
.city-combo-item small { display: block; font-size: 11px; color: #90a4ae; }
.city-combo-add { padding: 9px 12px; color: #2e7d32; font-weight: 700; cursor: pointer; border-top: 1px dashed #e0e0e0; }
.city-combo-add:hover { background: #f1f8f4; }
.nv-layout .form-group.nv-name input { font-size: 15px; font-weight: 600; }
.nv-layout .form-group.nv-address { order: 1; }   /* the full-width line last, so the photo fills the row beside «پیشە» */
/* Choices side by side as one segmented row (the page's .form-group label
   rule made each one a block of its own). */
.nv-layout .radio-group { flex-wrap: nowrap; gap: 6px; }
.nv-layout .form-group .radio-label { display: inline-flex; flex: 1 1 0; justify-content: center; margin: 0; padding: 9px 12px; }
/* «جۆری سەردان»: type and new/old first, then the work type and its doctor */
.nv-layout #patientStatusGroup { order: 1; }
.nv-layout .nv-worktype, .nv-layout #treatmentDoctorGroup { order: 2; }
.nv-card-hint { margin-inline-start: auto; font-size: 11.5px; color: #90a4ae; font-weight: 400; }
.nv-patient-notes { margin-top: 12px; }
/* Folding sections: health alerts, and the visit's rarely used part */
.nv-more { margin-top: 12px; border: 1px solid #e3e8ee; border-radius: 10px; padding: 0 12px; background: #fbfcfd; }
.nv-more > summary { cursor: pointer; padding: 10px 0; font-size: 13px; font-weight: 700; color: #37474f; list-style: none; display: flex; align-items: center; gap: 7px; }
.nv-more > summary::-webkit-details-marker { display: none; }
.nv-more > summary::after { content: ''; width: 7px; height: 7px; border: solid #90a4ae; border-width: 0 2px 2px 0; transform: rotate(45deg); margin-inline-start: auto; transition: transform .2s; }
.nv-more[open] > summary::after { transform: rotate(-135deg); }
.nv-more[open] { padding-bottom: 12px; }
.nv-more[open] > summary { border-bottom: 1px dashed #e0e0e0; margin-bottom: 12px; }
.nv-more-count { background: #fce4ec; color: #c2185b; border-radius: 9px; padding: 0 8px; font-size: 11px; }
.nv-more-count:empty { display: none; }
@media (max-width: 1100px) { .nv-layout { grid-template-columns: 1fr; } }

/* In the dialog: full width, and the save bar flush with its bottom edge */
body.is-embedded .report-container { max-width: none; }
body.is-embedded .form-actions { bottom: 0; margin: 12px -18px 0; border-radius: 0; border-width: 1px 0 0; }

/* The step-two choices as big cards with icons: the dot is hidden, the card is the choice */
.nv-wizard .nv-layout .form-group:has(> .radio-group input[name="visit_type"]),
.nv-wizard .nv-layout #patientStatusGroup { grid-column: span 2; }
.nv-wizard .nv-layout .radio-group:has(input[name="visit_type"]),
.nv-wizard .nv-layout .radio-group:has(input[name="patient_status"]) { gap: 10px; }
.nv-wizard .nv-layout .radio-label:has(input[name="visit_type"]),
.nv-wizard .nv-layout .radio-label:has(input[name="patient_status"]) {
    position: relative; min-width: 0; min-height: 92px; padding: 12px 18px; border: 2px solid #e2e8e4; border-radius: 14px;
    background: #fff; cursor: pointer; transition: border-color .15s, background-color .15s, box-shadow .15s, transform .15s;
}
.nv-wizard .nv-layout .radio-label:has(input[name="visit_type"]) input,
.nv-wizard .nv-layout .radio-label:has(input[name="patient_status"]) input { position: absolute; opacity: 0; pointer-events: none; }
.nv-wizard .nv-layout .radio-label:has(input[name="visit_type"]) span,
.nv-wizard .nv-layout .radio-label:has(input[name="patient_status"]) span {
    display: flex; flex-direction: column; align-items: center; gap: 8px; font-size: 16px; font-weight: 800; color: #37474f;
}
/* each choice its own colour — پشکنین blue, ئیش orange, نوێ green, کۆن purple */
.nv-wizard .nv-layout .radio-label { --c: #2e7d32; --c-soft: #f1f8f4; }
.nv-wizard .nv-layout .radio-label:has(input[name="visit_type"][value="checkup"]) { --c: #1e88e5; --c-soft: #eef6fe; }
.nv-wizard .nv-layout .radio-label:has(input[name="visit_type"][value="treatment"]) { --c: #ef6c00; --c-soft: #fff4e8; }
.nv-wizard .nv-layout .radio-label:has(input[name="patient_status"][value="new"]) { --c: #2e7d32; --c-soft: #eef8ef; }
.nv-wizard .nv-layout .radio-label:has(input[name="patient_status"][value="old"]) { --c: #8e24aa; --c-soft: #f7eefa; }
.nv-wizard .nv-layout .radio-label span i {
    width: 44px; height: 44px; border-radius: 12px; display: grid; place-items: center; font-size: 20px;
    background: var(--c-soft); color: var(--c); transition: background-color .15s, color .15s;
}
.nv-wizard .nv-layout .radio-label:has(input[name="visit_type"]):hover,
.nv-wizard .nv-layout .radio-label:has(input[name="patient_status"]):hover { border-color: color-mix(in srgb, var(--c) 45%, #fff); transform: translateY(-1px); }
.nv-wizard .nv-layout .radio-label:has(input:checked) {
    border-color: var(--c); background: var(--c-soft); box-shadow: 0 6px 16px color-mix(in srgb, var(--c) 18%, transparent);
}
.nv-wizard .nv-layout .radio-label:has(input:checked) span { color: var(--c); }
.nv-wizard .nv-layout .radio-label:has(input:checked) span i { background: var(--c); color: #fff; }
.nv-wizard .nv-layout .radio-label:has(input:focus-visible) { outline: 2px solid var(--c); outline-offset: 2px; }

/* «جۆری ئیش»: cards with icons (orange, like «ئیش»), the search beside the label */
.nv-layout .nv-worktype .wt-head { display: flex; align-items: center; gap: 12px; margin-bottom: 8px; }
.nv-layout .nv-worktype .wt-head label { margin: 0; }
.wt-search { position: relative; margin-inline-start: auto; width: min(260px, 60%); }
.wt-search i { position: absolute; inset-inline-start: 11px; top: 50%; transform: translateY(-50%); color: #90a4ae; font-size: 12px; pointer-events: none; }
.nv-layout .wt-search input { width: 100%; padding: 7px 10px; padding-inline-start: 31px; border: 1.5px solid #e2e8e4; border-radius: 9px; font-size: 13px; background: #fafbfa; }
.nv-layout .wt-search input:focus { border-color: #ef6c00; background: #fff; outline: none; box-shadow: 0 0 0 3px rgba(239, 108, 0, .12); }
.wt-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(112px, 1fr)); gap: 8px; }
.wt-card {
    position: relative; display: flex; flex-direction: column; align-items: center; gap: 6px; min-width: 0; min-height: 102px;
    padding: 10px 6px 8px; border: 2px solid #e2e8e4; border-radius: 12px; background: #fff; cursor: pointer; font: inherit;
    transition: border-color .15s, background-color .15s, box-shadow .15s, transform .15s;
}
.wt-card:hover { border-color: #ffc28a; transform: translateY(-1px); }
.wt-card:focus-visible { outline: 2px solid #ef6c00; outline-offset: 2px; }
.wt-ico { width: 44px; height: 44px; flex-shrink: 0; display: grid; place-items: center; }
.wt-name {
    font-size: 12.5px; font-weight: 700; color: #37474f; line-height: 1.35; text-align: center;
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
}
.wt-card.is-on { border-color: #ef6c00; background: #fff4e8; box-shadow: 0 6px 16px rgba(239, 108, 0, .16); }
.wt-card.is-on .wt-name { color: #c25400; }
.wt-card.is-on::after {
    content: '\2713'; position: absolute; top: 6px; inset-inline-start: 6px; width: 18px; height: 18px; border-radius: 50%;
    background: #ef6c00; color: #fff; font-size: 11px; line-height: 18px; text-align: center;
}
.wt-card--add { border-style: dashed; background: #fbfcfd; }
.wt-card--add .wt-ico i { width: 40px; height: 40px; border-radius: 12px; display: grid; place-items: center; background: #eef2f6; color: #607d8b; font-size: 16px; }
.wt-card--add .wt-name { color: #607d8b; }
.wt-card--add:hover { border-color: #90a4ae; }
.wt-more { display: block; margin: 8px auto 0; padding: 5px 14px; border: none; border-radius: 8px; background: none; color: #ef6c00; font: inherit; font-size: 12.5px; font-weight: 700; cursor: pointer; }
.wt-more:hover { background: #fff4e8; }
/* the add dialog: examples to click, and the icon to choose */
.wt-examples { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-top: 10px; }
.wt-examples > span { font-size: 12px; color: #78909c; margin-inline-end: 2px; }
.wt-ex {
    display: inline-flex; align-items: center; gap: 5px; padding: 3px; padding-inline: 5px 10px; border: 1px solid #e2e8f0;
    border-radius: 999px; background: #fff; font: inherit; font-size: 12px; color: #37474f; cursor: pointer;
}
.wt-ex:hover { border-color: #93c5fd; background: #f5f9ff; }
.wt-ex-ico { width: 20px; height: 20px; flex-shrink: 0; }
#newWorkTypeIconsNv { max-height: 236px; overflow-y: auto; padding: 7px 7px 4px; }

/* ── Three steps, one at a time ── */
.nv-wizard .nv-layout { display: block; }
.nv-wizard [data-step]:not(.is-step-on) { display: none !important; }
.nv-wizard .form-card > .form-card-header { display: none; }   /* the step bar names each step */
.nv-steps { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; margin: 0 0 12px; }
.nv-step { position: relative; display: flex; align-items: center; gap: 10px; padding: 10px 14px; border: 1.5px solid #e2e8e4; border-radius: 12px;
           background: #fff; font: inherit; text-align: right; cursor: pointer; color: #546e63; transition: border-color .15s, background-color .15s, box-shadow .15s; }
.nv-step b { flex: 0 0 auto; width: 30px; height: 30px; border-radius: 50%; display: grid; place-items: center; background: #eef3ef; color: #607d6b; font-size: 14px; }
.nv-step span { display: flex; flex-direction: column; font-size: 14px; font-weight: 800; }
.nv-step small { font-size: 11px; font-weight: 400; color: #90a4ae; }
.nv-step.is-on { border-color: #2e7d32; background: #f1f8f4; color: #1b5e20; box-shadow: 0 4px 14px rgba(46, 125, 50, .14); }
.nv-step.is-on b { background: #2e7d32; color: #fff; }
.nv-step.is-done b { background: #c8e6c9; color: #1b5e20; font-size: 0; }
.nv-step.is-done b::after { content: '\2713'; font-size: 14px; }
.nv-step.is-locked { cursor: not-allowed; opacity: .55; }
.nv-step.is-off { cursor: default; opacity: .45; background: #f6f7f8; }
.nv-step.is-off span { text-decoration: line-through; text-decoration-color: rgba(0, 0, 0, .25); }
.nv-need { margin-inline-start: auto; font-size: 12.5px; color: #c62828; font-weight: 600; }
.nv-need:empty { display: none; }
.form-actions .nv-next { background: #2e7d32; border-color: #2e7d32; }
.form-actions .nv-next:disabled { opacity: .45; cursor: not-allowed; }
.form-actions [hidden] { display: none !important; }

@media (max-width: 600px) {
    .nv-steps { gap: 4px; }
    .nv-step { padding: 8px; gap: 6px; }
    .nv-step small { display: none; }
    .form-actions { bottom: 0; border-radius: 10px 10px 0 0; }
    .form-actions .btn { flex: 1; justify-content: center; }
    .nv-search-hint { display: none; }
}
</style>

<?php
// Three steps, one at a time: a failed save opens on the step it failed in.
$nvErr = $errors ?? [];
$nvStartStep = 1;
if (!empty($nvErr) && !isset($nvErr['full_name']) && !isset($nvErr['phone']) && !isset($nvErr['gender'])) {
    $nvStartStep = isset($nvErr['visit_type']) ? 2 : 3;
}
?>
<form method="POST" action="" enctype="multipart/form-data" id="nvForm" class="nv-wizard" novalidate data-start-step="<?= (int)$nvStartStep ?>">
    <input type="hidden" id="patient_id" name="patient_id" value="<?php echo htmlspecialchars($_POST['patient_id'] ?? ''); ?>">
    <input type="hidden" id="patient_dirty" name="patient_dirty" value="<?php echo htmlspecialchars($_POST['patient_dirty'] ?? '0'); ?>">
    <input type="hidden" id="patient_conditions_loaded" name="patient_conditions_loaded" value="<?php echo htmlspecialchars($_POST['patient_conditions_loaded'] ?? '0'); ?>">

    <?php if (!$embedLayout): ?>
    <!-- Page Header (the dialog has its own title) -->
    <div class="report-header">
        <div class="report-title">
            <div class="icon"><i class="fas fa-user-plus"></i></div>
            <h2>سەردانی نوێ</h2>
        </div>
    </div>
    <?php endif; ?>

    <!-- The three steps: each opens once the one before it is filled in -->
    <nav class="nv-steps" id="nvSteps" aria-label="هەنگاوەکان">
        <button type="button" class="nv-step" data-go="1"><b>١</b><span>نەخۆش<small>کۆن: گەڕان · نوێ: بنووسە</small></span></button>
        <button type="button" class="nv-step" data-go="2"><b>٢</b><span>جۆری سەردان<small>پشکنین یان ئیش</small></span></button>
        <button type="button" class="nv-step" data-go="3"><b>٣</b><span>وردەکاری پشکنین<small id="nvStep3Note">تەنها بۆ پشکنین</small></span></button>
    </nav>

    <!-- Patient Search Card -->
    <div class="search-card" data-step="1">
        <div class="search-row">
            <div class="search-input-wrapper">
                <input type="text" id="patient_search" placeholder="نەخۆسی کۆن؟ گەڕان بە ناو یان ژمارەی مۆبایل — نەخۆشی نوێ؟ ڕاستەوخۆ لە خوارەوە بنووسە" autocomplete="off" autofocus>
                <div id="patient_search_results" class="search-results"></div>
            </div>
            <span class="nv-search-hint"><kbd>/</kbd> بۆ گەڕانی خێرا</span>
            <button type="button" id="clear_patient_selection" class="btn-clear" style="display:none;">
                <i class="fas fa-times"></i> پاککردنەوە
            </button>
        </div>
    </div>

    <!-- Two columns on a wide screen: the patient (right), the visit (left). -->
    <div class="nv-layout">
    <div class="nv-col">

    <!-- Patient Personal Info Card (merged: basic + contact + notes + medical conditions) -->
    <div class="form-card" data-step="1">
        <div class="form-card-header">
            <div class="card-icon icon-blue"><i class="fas fa-id-card"></i></div>
            <h3>نەخۆش</h3>
            <span class="nv-card-hint">کۆن: لە گەڕانەکەوە · نوێ: لێرە بنووسە</span>
        </div>
        <div class="form-card-body">
            <div class="form-grid">
                <div class="form-group nv-name" style="position:relative;">
                    <label><span class="required">*</span> ناوی تەواو</label>
                    <input type="text" id="full_name" name="full_name" class="<?php echo isset($errors['full_name']) ? 'error' : ''; ?>"
                           value="<?php echo htmlspecialchars($_POST['full_name'] ?? ($_POST['patient_name'] ?? '')); ?>" required placeholder="ناوی سیانی" autocomplete="off">
                    <div id="full_name_suggestions" class="search-results" style="display:none;position:absolute;z-index:999;width:100%;top:100%;left:0;"></div>
                </div>
                <div class="form-group">
                    <label><span class="required">*</span> ڕەگەز</label>
                    <select id="gender" name="gender" class="<?php echo isset($errors['gender']) ? 'error' : ''; ?>" required>
                        <option value="">هەڵبژاردن</option>
                        <option value="male" <?php echo (($_POST['gender'] ?? '') === 'male') ? 'selected' : ''; ?>>نێر</option>
                        <option value="female" <?php echo (($_POST['gender'] ?? '') === 'female') ? 'selected' : ''; ?>>مێ</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>بەرواری لەدایکبوون</label>
                    <input type="date" id="date_of_birth" name="date_of_birth" value="<?php echo htmlspecialchars($_POST['date_of_birth'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>جۆری خوێن</label>
                    <select id="blood_type" name="blood_type">
                        <option value="">دیاری نەکراو</option>
                        <?php
                        $blood_types = ['A+','A-','B+','B-','AB+','AB-','O+','O-'];
                        $selected_blood = $_POST['blood_type'] ?? '';
                        foreach ($blood_types as $bt):
                            $selected = ($selected_blood === $bt) ? 'selected' : '';
                            echo "<option value=\"{$bt}\" {$selected}>{$bt}</option>";
                        endforeach;
                        ?>
                    </select>
                </div>
                <div class="form-group">
                    <label><span class="required">*</span> ژمارەی مۆبایل</label>
                    <input type="tel" id="phone" name="phone" class="<?php echo isset($errors['phone']) ? 'error' : ''; ?>"
                           value="<?php echo htmlspecialchars($_POST['phone'] ?? ($_POST['mobile_number'] ?? '')); ?>" required placeholder="0750-123-4567">
                </div>
                <?php // «ژمارەی دووەم» is not asked: hidden, so a returning patient's second number is kept ?>
                <div class="form-group" hidden>
                    <label>ژمارەی دووەم</label>
                    <input type="tel" id="phone2" name="phone2" value="<?php echo htmlspecialchars($_POST['phone2'] ?? ''); ?>" placeholder="0770-987-6543">
                </div>
                <?php
                // شوێن: one search over every گەڕەک (with its شار, ئیدارە and پارێزگا);
                // «+» adds a new one. Typed text that is not picked stays the city, as before.
                $selected_city = $_POST['city'] ?? '';
                $selected_area = $_POST['area'] ?? '';
                $selected_loc  = (int)($_POST['location_id'] ?? 0);
                $selected_show = $selected_loc && $selected_area !== '' ? $selected_area . '، ' . $selected_city : $selected_city;
                ?>
                <div class="form-group nv-place">
                    <label>شوێن <small style="color:#90a4ae;font-weight:400;">(شار · گەڕەک)</small></label>
                    <div style="display: flex; gap: 4px;">
                        <div class="city-combo">
                            <input type="text" id="cityComboInput" placeholder="گەڕەک یان شار بنووسە…" autocomplete="off"
                                   value="<?php echo htmlspecialchars($selected_show); ?>"
                                   oninput="cityComboFilter()" onfocus="cityComboOpen()">
                            <input type="hidden" id="city" name="city" value="<?php echo htmlspecialchars($selected_city); ?>">
                            <input type="hidden" id="area" name="area" value="<?php echo htmlspecialchars($selected_area); ?>">
                            <input type="hidden" id="location_id" name="location_id" value="<?php echo $selected_loc ?: ''; ?>">
                            <div id="cityComboDropdown" class="city-combo-dropdown"></div>
                        </div>
                        <button type="button" onclick="openAddCityDialog()" title="شوێنی نوێ: پارێزگا، شار، گەڕەک"
                                style="padding: 0 10px; background: var(--win-accent, #0078d4); color: #fff; border: 1px solid var(--win-accent, #0078d4); border-radius: 4px; cursor: pointer;">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>
                </div>
                <?php // «پیشە» is not asked: the field stays (hidden) so a returning patient's job is kept ?>
                <div class="form-group" hidden>
                    <label>پیشە</label>
                    <input type="text" id="occupation" name="occupation" value="<?php echo htmlspecialchars($_POST['occupation'] ?? ''); ?>" placeholder="کارمەند">
                </div>
                <div class="form-group span-2 nv-address">
                    <label>ناونیشان</label>
                    <input type="text" id="address" name="address" value="<?php echo htmlspecialchars($_POST['address'] ?? ''); ?>" placeholder="ناونیشانی وردی ماڵەوە...">
                </div>
                <div class="form-group span-2 nv-photo">
                    <label>وێنەی نەخۆش</label>
                    <input type="file" id="profile_image" name="profile_image" accept="image/*">
                    <div id="profile_image_preview" class="profile-preview" style="display:none;">
                        <a id="profile_image_preview_link" href="#" target="_blank"><i class="fas fa-external-link-alt"></i> بینین</a>
                    </div>
                </div>
            </div>

            <div class="form-group nv-patient-notes">
                <label><i class="fas fa-sticky-note"></i> تێبینی نەخۆش</label>
                <textarea id="patient_notes" name="patient_notes" rows="2" placeholder="تێبینی تایبەت بۆ نەخۆش..."><?php echo htmlspecialchars($_POST['patient_notes'] ?? ''); ?></textarea>
            </div>

            <?php $nvHealthOpen = !empty($_POST['medical_conditions']); ?>
            <details class="nv-more" id="nvHealth" <?= $nvHealthOpen ? 'open' : '' ?>>
                <summary><i class="fas fa-heartbeat" style="color:#e91e63;"></i> ئاگاداری تەندروستی
                    <span class="nv-more-count" id="nvHealthCount"></span></summary>
                <div class="conditions-grid">
                    <?php foreach ($MEDICAL_CONDITIONS as $key => $label): ?>
                        <div class="condition-card <?php echo in_array($key, $_POST['medical_conditions'] ?? []) ? 'active' : ''; ?>" id="card_<?php echo $key; ?>" onclick="toggleConditionCard('<?php echo $key; ?>')">
                            <div class="condition-header">
                                <input type="checkbox" name="medical_conditions[]" value="<?php echo $key; ?>"
                                       id="condition_<?php echo $key; ?>" class="condition-checkbox"
                                       onclick="event.stopPropagation(); toggleConditionDetails('<?php echo $key; ?>')"
                                       <?php echo in_array($key, $_POST['medical_conditions'] ?? []) ? 'checked' : ''; ?>>
                                <label for="condition_<?php echo $key; ?>"><?php echo $label; ?></label>
                            </div>
                            <div id="details_<?php echo $key; ?>" class="condition-details" onclick="event.stopPropagation()">
                                <input type="text" name="condition_details[<?php echo $key; ?>]" placeholder="وردەکاری..." value="<?php echo htmlspecialchars($_POST['condition_details'][$key] ?? ''); ?>">
                                <select name="condition_severity[<?php echo $key; ?>]">
                                    <option value="low" <?php echo (($_POST['condition_severity'][$key] ?? '') === 'low') ? 'selected' : ''; ?>>کەم</option>
                                    <option value="medium" <?php echo (($_POST['condition_severity'][$key] ?? 'medium') === 'medium') ? 'selected' : ''; ?>>مامناوەند</option>
                                    <option value="high" <?php echo (($_POST['condition_severity'][$key] ?? '') === 'high') ? 'selected' : ''; ?>>زۆر</option>
                                </select>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </details>
        </div>
    </div>

    </div><!-- /.nv-col (patient) -->
    <div class="nv-col">

    <!-- Visit type: checkup or work, new or returning patient -->
    <div class="form-card" data-step="2">
        <div class="form-card-header">
            <div class="card-icon icon-orange"><i class="fas fa-clipboard-check"></i></div>
            <h3>جۆری سەردان</h3>
        </div>
        <div class="form-card-body">
            <div class="form-grid">
                <div class="form-group">
                    <label><span class="required">*</span> جۆری نەخۆش</label>
                    <div class="radio-group">
                        <label class="radio-label">
                            <input type="radio" name="visit_type" value="checkup" required onchange="toggleCheckupSection()" <?php echo (($_POST['visit_type'] ?? '') === 'checkup') ? 'checked' : ''; ?>>
                            <span><i class="fas fa-stethoscope"></i>پشکنین</span>
                        </label>
                        <label class="radio-label">
                            <input type="radio" name="visit_type" value="treatment" required onchange="toggleCheckupSection()" <?php echo (($_POST['visit_type'] ?? '') === 'treatment') ? 'checked' : ''; ?>>
                            <span><i class="fas fa-tooth"></i>ئیش</span>
                        </label>
                    </div>
                </div>
                <div class="form-group span-2 nv-worktype">
                    <?php
                    // The list once, for the cards (value = id)
                    $selected_work_type = $_POST['work_type_id'] ?? '';
                    $work_types_list = [];
                    if ($work_types_for_modal) {
                        $work_types_for_modal->data_seek(0);
                        while ($wt = $work_types_for_modal->fetch_assoc()) {
                            $work_types_list[] = ['id' => (int)$wt['id'], 'name' => $wt['work_name'], 'icon' => $wt['icon'], 'uses' => (int)$wt['uses']];
                        }
                    }
                    ?>
                    <div class="wt-head">
                        <label>جۆری ئیش</label>
                        <div class="wt-search">
                            <i class="fas fa-search"></i>
                            <input type="search" id="wtSearch" placeholder="گەڕان بۆ ئیش…" autocomplete="off" aria-label="گەڕان بۆ جۆری ئیش">
                        </div>
                    </div>
                    <input type="hidden" id="work_type_id" name="work_type_id" value="<?php echo htmlspecialchars((string)$selected_work_type); ?>">
                    <div id="wtCards" class="wt-cards" role="radiogroup" aria-label="جۆری ئیش"></div>
                    <button type="button" id="wtMore" class="wt-more" hidden></button>
                </div>
                <?php
                // Materialise the doctor list once — the mysqli result is walked
                // again further down, so rewind it afterwards.
                $doctors->data_seek(0);
                $doctors_list = [];
                while ($d = $doctors->fetch_assoc()) { $doctors_list[] = $d; }
                $doctors->data_seek(0);
                ?>
                <div class="form-group" id="treatmentDoctorGroup" style="display: none;">
                    <label><i class="fas fa-user-md"></i> دکتۆری ئیش</label>
                    <select id="treatment_doctor_id" name="treatment_doctor_id">
                        <option value="">هەڵبژاردن...</option>
                        <?php foreach ($doctors_list as $doctor): ?>
                            <option value="<?php echo (int)$doctor['id']; ?>"
                                <?php echo ((string)($_POST['treatment_doctor_id'] ?? '') === (string)$doctor['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($doctor['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" id="patientStatusGroup">
                    <label><span class="required">*</span> دۆخی نەخۆش</label>
                    <div class="radio-group">
                        <label class="radio-label">
                            <input type="radio" name="patient_status" value="new" required <?php echo (($_POST['patient_status'] ?? '') === 'new') ? 'checked' : ''; ?>>
                            <span><i class="fas fa-user-plus"></i>نوێ</span>
                        </label>
                        <label class="radio-label">
                            <input type="radio" name="patient_status" value="old" required <?php echo (($_POST['patient_status'] ?? '') === 'old') ? 'checked' : ''; ?>>
                            <span><i class="fas fa-user-clock"></i>کۆن</span>
                        </label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Visit Info for Checkup (merged: doctor + time/details + admin + appointment + notes) -->
    <div class="form-card" id="checkupSection" data-step="3">
        <div class="form-card-header">
            <div class="card-icon icon-green"><i class="fas fa-clipboard-list"></i></div>
            <h3>وردەکاری پشکنین</h3>
        </div>
        <div class="form-card-body">

            <!-- Sub-section: Doctor Information -->
            <div class="subsection-label"><i class="fas fa-user-md"></i> زانیاری دکتۆر</div>
            <div class="form-grid-3">
                <div class="form-group">
                    <label>دکتۆری داواکراو</label>
                    <select id="requested_doctor_id" name="requested_doctor_id">
                        <option value="">هەڵبژاردن...</option>
                        <?php while ($doctor = $doctors->fetch_assoc()): ?>
                            <option value="<?php echo $doctor['id']; ?>" <?= (string) ($_POST['requested_doctor_id'] ?? '') === (string) $doctor['id'] ? 'selected' : '' ?>><?php echo htmlspecialchars($doctor['name']); ?></option>
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
                            <option value="<?php echo $doctor['id']; ?>" <?= (string) ($_POST['assigned_doctor_id'] ?? '') === (string) $doctor['id'] ? 'selected' : '' ?>><?php echo htmlspecialchars($doctor['name']); ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>هۆکاری دیاریکردن</label>
                    <input type="text" id="assigned_doctor_reason" name="assigned_doctor_reason" placeholder="هۆکار..." autocomplete="off">
                </div>
            </div>

            <hr class="green-separator">

            <!-- Sub-section: Time & Visit Details -->
            <div class="subsection-label"><i class="fas fa-clock"></i> کات و وردەکاری سەردان</div>
            <div class="form-grid">
                <div class="form-group">
                    <div class="nv-label-row">
                        <label><span class="required">*</span> کاتی هاتن</label>
                        <button type="button" class="nv-now-btn" onclick="nvSetNow('arrival_time')">ئێستا</button>
                    </div>
                    <input type="time" id="arrival_time" name="arrival_time" required>
                </div>
                <div class="form-group">
                    <div class="nv-label-row">
                        <label>کاتی چوونەژوورەوە</label>
                        <button type="button" class="nv-now-btn" onclick="nvSetNow('checkin_time')">ئێستا</button>
                    </div>
                    <input type="time" id="checkin_time" name="checkin_time">
                </div>
                <div class="form-group">
                    <div class="nv-label-row">
                        <label>کاتی ئاگادارکردنەوە</label>
                        <button type="button" class="nv-now-btn" onclick="nvSetNow('notification_time')">ئێستا</button>
                    </div>
                    <input type="time" id="notification_time" name="notification_time">
                </div>
                <div class="form-group">
                    <label>بەرواری سەردان</label>
                    <input type="date" id="visit_date" name="visit_date" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="form-group" id="checkupPriceGroup">
                    <label>نرخ</label>
                    <input type="number" id="price" name="price" min="0" step="0.01" inputmode="decimal"
                           value="<?php echo htmlspecialchars($_POST['price'] ?? ''); ?>" placeholder="0.00">
                </div>
                <div class="form-group span-4">
                    <label>ئیش (چارەسەر)</label>
                    <input type="text" id="procedure_treatment" name="procedure_treatment" list="procedures_list" placeholder="هەڵبژاردن یان نوسین..." autocomplete="off">
                    <datalist id="procedures_list">
                        <?php while ($proc = $procedures->fetch_assoc()): ?>
                            <option value="<?php echo htmlspecialchars($proc['name']); ?>">
                        <?php endwhile; ?>
                    </datalist>
                </div>
            </div>

            <hr class="green-separator">

            <!-- Sub-section 2: Administrative -->
            <div class="subsection-label"><i class="fas fa-cog"></i> زانیاری ئیداری</div>
            <div class="form-grid">
                <div class="form-group">
                    <label>کارمەندی پێشوازی</label>
                    <select id="receptionist_id" name="receptionist_id">
                        <option value="">هەڵبژاردن...</option>
                        <?php while ($receptionist = $receptionists->fetch_assoc()): ?>
                            <option value="<?php echo $receptionist['id']; ?>" <?php echo $receptionist['id'] == $currentUser['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($receptionist['full_name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>سەرچاوەی هاتن</label>
                    <input type="text" id="referral_source" name="referral_source" list="referral_sources_list" placeholder="لەڕێگەی کێ هاتووە..." autocomplete="off">
                    <datalist id="referral_sources_list">
                        <?php while ($source = $referral_sources->fetch_assoc()): ?>
                            <option value="<?php echo htmlspecialchars($source['name']); ?>">
                        <?php endwhile; ?>
                    </datalist>
                </div>
                <div class="form-group">
                    <label>تێبینی سەرچاوە</label>
                    <input type="text" id="referral_source_notes" name="referral_source_notes" list="doctors_list_referral" placeholder="تێبینی..." autocomplete="off">
                    <datalist id="doctors_list_referral">
                        <?php
                        $doctors->data_seek(0);
                        while ($doctor = $doctors->fetch_assoc()):
                        ?>
                            <option value="<?php echo htmlspecialchars($doctor['name']); ?>">
                        <?php endwhile; ?>
                    </datalist>
                </div>
            </div>

            <div class="form-group" style="margin-top: 12px;">
                <label><i class="fas fa-comment-alt"></i> تێبینی سەردان</label>
                <textarea id="notes" name="notes" rows="2" placeholder="تێبینی زیادە..."></textarea>
            </div>

            <?php
            // The rarely used part folds away; it opens by itself when it was
            // filled in before a failed save, so nothing typed is hidden.
            $nvMoreOpen = !empty($_POST['appointment_date']) || !empty($_POST['referring_doctor_id'])
                || !empty($_POST['referring_doctor_designation']) || !empty($errors['patient_referral'])
                || ($_POST['treated'] ?? '') === 'yes';
            ?>
            <details class="nv-more" id="nvVisitMore" <?= $nvMoreOpen ? 'open' : '' ?>>
                <summary><i class="fas fa-sliders-h"></i> زیاتر: ئیش بۆ کرا؟ · چاوپێکەوتن و دەستنیشانکردن · پلانی چارەسەر</summary>
            <div class="form-grid">
                <div class="form-group">
                    <label>بۆ ئیش بۆ کرا</label>
                    <div class="radio-group">
                        <label class="radio-label">
                            <input type="radio" name="treated" value="yes" onchange="toggleTreatedReason()">
                            <span>بەڵێ</span>
                        </label>
                        <label class="radio-label">
                            <input type="radio" name="treated" value="no" checked onchange="toggleTreatedReason()">
                            <span>نەخێر</span>
                        </label>
                    </div>
                </div>
                <div class="form-group span-3" id="treated_reason_container">
                    <label>هۆکاری چارەسەر نەکردن</label>
                    <input type="text" id="treated_reason" name="treated_reason" placeholder="بۆچی چارەسەر نەکرا؟" autocomplete="off">
                </div>
            </div>

            <hr class="green-separator">

            <!-- Sub-section 3: Appointment -->
            <div class="subsection-label"><i class="fas fa-calendar-check"></i> زانیاری چاوپێکەوتن</div>
            <div class="form-grid">
                <div class="form-group">
                    <label>بەرواری چاوپێکەوتن</label>
                    <input type="date" id="appointment_date" name="appointment_date">
                </div>
                <div class="form-group">
                    <label>دکتۆری دەستنیشانکەر</label>
                    <select id="referring_doctor_id" name="referring_doctor_id">
                        <option value="">هەڵبژاردن...</option>
                        <?php
                        $doctors->data_seek(0);
                        while ($doctor = $doctors->fetch_assoc()):
                        ?>
                            <option value="<?php echo $doctor['id']; ?>" <?= (string) ($_POST['referring_doctor_id'] ?? '') === (string) $doctor['id'] ? 'selected' : '' ?>><?php echo htmlspecialchars($doctor['name']); ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>دکتۆری دەستنیشانکراو</label>
                    <select id="referring_doctor_designation" name="referring_doctor_designation" onchange="toggleReferringDoctorChoice()">
                        <option value="">هەڵبژاردن...</option>
                        <option value="DOCTORS" <?= ($_POST['referring_doctor_designation'] ?? '') === 'DOCTORS' ? 'selected' : '' ?>>چەند دکتۆرێک</option>
                        <option value="PATIENT" <?= ($_POST['referring_doctor_designation'] ?? '') === 'PATIENT' ? 'selected' : '' ?>>نەخۆش بەخۆی دیاریکرد</option>
                        <?php
                        $doctors->data_seek(0);
                        while ($doctor = $doctors->fetch_assoc()):
                        ?>
                            <option value="<?php echo $doctor['id']; ?>" <?= (string) ($_POST['referring_doctor_designation'] ?? '') === (string) $doctor['id'] ? 'selected' : '' ?>><?php echo htmlspecialchars($doctor['name']); ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>پلانی چارەسەر</label>
                    <input type="file" id="appointment_image" name="appointment_image" accept="image/*,.pdf">
                </div>
            </div>
            <div id="referring_doctor_choice_container" style="display: <?= in_array($_POST['referring_doctor_designation'] ?? '', ['DOCTORS', 'PATIENT'], true) ? 'block' : 'none' ?>; margin-top: 10px;">
                <div class="form-grid-2">
                    <div class="form-group">
                        <label>کارمەندی دەستنیشانکەر</label>
                        <select id="appointment_added_by" name="appointment_added_by">
                            <option value="">هەڵبژاردن...</option>
                            <?php
                            $receptionists->data_seek(0);
                            while ($receptionist = $receptionists->fetch_assoc()):
                            ?>
                                <option value="<?php echo $receptionist['id']; ?>" <?php echo $receptionist['id'] == $currentUser['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($receptionist['full_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>دکتۆری هەڵبژێردراو</label>
                        <select id="referring_doctor_choice_id" name="referring_doctor_choice_id">
                            <option value="">هەڵبژاردن...</option>
                            <?php
                            $doctors->data_seek(0);
                            while ($doctor = $doctors->fetch_assoc()):
                            ?>
                                <option value="<?php echo $doctor['id']; ?>" <?= (string) ($_POST['referring_doctor_choice_id'] ?? '') === (string) $doctor['id'] ? 'selected' : '' ?>><?php echo htmlspecialchars($doctor['name']); ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>
            </div>
            <?php if (!empty($errors['patient_referral'])): ?><div role="alert" style="color:#c62828;"><?= htmlspecialchars($errors['patient_referral']) ?></div><?php endif; ?>
            </details>
        </div>
    </div>

    </div><!-- /.nv-col (visit) -->
    </div><!-- /.nv-layout -->

    <!-- Form Actions (sticky — always visible) -->
    <div class="form-actions">
        <?php if ($embedLayout): ?>
        <button type="button" class="btn btn-secondary" onclick="nvEmbedClose()">
            <i class="fas fa-times"></i> هەڵوەشاندنەوە
        </button>
        <?php else: ?>
        <a href="<?= APP_URL ?>/visits.php" class="btn btn-secondary">
            <i class="fas fa-times"></i> هەڵوەشاندنەوە
        </a>
        <?php endif; ?>
        <button type="button" class="btn btn-secondary nv-back" id="nvBack" hidden>
            <i class="fas fa-arrow-right"></i> پێشوو
        </button>
        <span class="nv-need" id="nvNeed"></span>
        <button type="button" class="btn btn-primary nv-next" id="nvNext">
            <span>دواتر<small><kbd>Enter</kbd></small></span> <i class="fas fa-arrow-left"></i>
        </button>
        <button type="submit" id="nvBtnSave" class="btn btn-primary">
            <i class="fas fa-save"></i>
            <span>تۆمارکردن<small><kbd>Ctrl</kbd> + <kbd>Enter</kbd></small></span>
        </button>
        <button type="submit" id="nvBtnSaveSend" name="send_to_waiting" value="1" class="btn btn-primary btn-save-send">
            <i class="fas fa-user-clock"></i>
            <span>تۆمارکردن و ناردن بۆ ژووری چاوەروانی<small><kbd>Ctrl</kbd> + <kbd>Shift</kbd> + <kbd>Enter</kbd></small></span>
        </button>
    </div>
</form>
</div>

<script src="<?= asset_url('assets/js/treatment-icons.js') ?>"></script>
<script>
// ── Inside the «سەردانی نوێ» dialog (?embed=1, includes/new_visit_dialog.php) ──
// The parent page owns the dialog: it is told to close (and reload its list
// once a visit was saved), and links to other pages open there, not in here.
var NV_EMBED = <?= $embedLayout ? 'true' : 'false' ?>;
function nvParent(msg) {
    try { if (window.parent !== window) window.parent.postMessage(Object.assign({ source: 'new-visit' }, msg), window.location.origin); } catch (e) {}
}
function nvEmbedClose() { nvParent({ action: 'close' }); }
function nvGo(url) {
    if (NV_EMBED) { nvParent({ action: 'navigate', url: url }); return; }
    window.location.href = url;
}

// «ئاگاداری تەندروستی» folds away; its title says how many are ticked.
document.addEventListener('DOMContentLoaded', function () {
    var box = document.getElementById('nvHealth');
    if (!box) return;
    var count = function () {
        var n = box.querySelectorAll('.condition-checkbox:checked').length;
        var el = document.getElementById('nvHealthCount');
        if (el) el.textContent = n ? n + ' دیاریکراو' : '';
        if (n) box.open = true;
    };
    box.addEventListener('change', count);
    box.addEventListener('click', function () { setTimeout(count, 0); });
    count();
    // A returning patient's conditions are filled in by the search: show them.
    new MutationObserver(count).observe(box, { subtree: true, attributes: true, attributeFilter: ['class'] });
});

// Set current time as default for arrival time
document.addEventListener('DOMContentLoaded', function() {
    const now = new Date();
    const hours = String(now.getHours()).padStart(2, '0');
    const minutes = String(now.getMinutes()).padStart(2, '0');
    const currentTime = hours + ':' + minutes;

    const arrivalTimeInput = document.getElementById('arrival_time');
    if (!arrivalTimeInput.value) {
        arrivalTimeInput.value = currentTime;
    }

    // Sync 'زانیاری سەردان بۆ پشکنین' visibility with the visit_type radio's initial state
    if (typeof toggleCheckupSection === 'function') {
        toggleCheckupSection();
    }
});

// One-tap "now" button next to time fields
function nvSetNow(id) {
    const el = document.getElementById(id);
    if (!el) return;
    const n = new Date();
    el.value = String(n.getHours()).padStart(2, '0') + ':' + String(n.getMinutes()).padStart(2, '0');
    el.dispatchEvent(new Event('change', { bubbles: true }));
}

// Reception keyboard shortcuts:
//   "/"                → jump to patient search
//   Ctrl+Enter         → save
//   Ctrl+Shift+Enter   → save + send to waiting room
document.addEventListener('keydown', function(e) {
    const tag = (document.activeElement && document.activeElement.tagName) || '';
    const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(tag);

    if (e.key === '/' && !typing) {
        const search = document.getElementById('patient_search');
        if (search) { e.preventDefault(); search.focus(); search.select(); }
        return;
    }
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
        e.preventDefault();
        // saves from any step once everything needed is in; else shows what is missing
        if (window.nvWizard && !window.nvWizard.readyToSave()) return;
        const btn = e.shiftKey
            ? document.getElementById('nvBtnSaveSend')
            : document.getElementById('nvBtnSave');
        if (btn) { window.nvWizard && window.nvWizard.saving(); btn.click(); }
    }
});

// ── Three steps: ١ نەخۆش → ٢ جۆری سەردان → ٣ وردەکاری پشکنین ──
// «دواتر» opens once the step's required fields are in. A work (ئیش) visit has
// no step three: it is saved from step two. Enter in a field goes on; the save
// buttons (or Ctrl+Enter) save. The form is novalidate — the steps check what
// the server requires, and the server checks again.
window.nvWizard = (function () {
    const form = document.getElementById('nvForm');
    if (!form) return null;
    let step = Number(form.dataset.startStep) || 1;
    let saveClicked = false;
    const $ = id => document.getElementById(id);
    const val = id => (($(id) || {}).value || '').trim();
    const visitType = () => (form.querySelector('input[name="visit_type"]:checked') || {}).value || '';
    const lastStep = () => visitType() === 'treatment' ? 2 : 3;

    // what a step still needs: [field to focus, its name]
    function missing(n) {
        const m = [];
        if (n === 1) {
            if (!val('full_name')) m.push(['full_name', 'ناو']);
            if (!val('gender')) m.push(['gender', 'ڕەگەز']);
            if (!val('phone')) m.push(['phone', 'ژمارەی مۆبایل']);
        } else if (n === 2) {
            if (!visitType()) m.push(['visit_type', 'جۆری نەخۆش (پشکنین یان ئیش)']);
            else if (visitType() === 'checkup' && !form.querySelector('input[name="patient_status"]:checked')) m.push(['patient_status', 'دۆخی نەخۆش (نوێ یان کۆن)']);
        } else if (n === 3 && visitType() === 'checkup') {
            if (!val('arrival_time')) m.push(['arrival_time', 'کاتی هاتن']);
        }
        return m;
    }
    const firstInvalid = upTo => { for (let n = 1; n <= upTo; n++) if (missing(n).length) return n; return 0; };

    function focusIn(n) {
        const need = missing(n)[0];
        let el = need ? ($(need[0]) || form.querySelector('[name="' + need[0] + '"]')) : null;
        if (!el) el = form.querySelector('[data-step="' + n + '"] input:not([type="hidden"]):not([type="file"]), [data-step="' + n + '"] select');
        if (el) try { el.focus({ preventScroll: true }); } catch (e) {}
    }

    function render() {
        const last = lastStep();
        if (step > last) step = last;
        form.querySelectorAll('[data-step]').forEach(el => el.classList.toggle('is-step-on', Number(el.dataset.step) === step));
        document.querySelectorAll('#nvSteps .nv-step').forEach(b => {
            const n = Number(b.dataset.go);
            const off = n > last;
            b.classList.toggle('is-on', n === step);
            b.classList.toggle('is-off', off);
            b.classList.toggle('is-done', !off && n !== step && !missing(n).length && n < step);
            b.classList.toggle('is-locked', !off && n > step && firstInvalid(n - 1) !== 0);
        });
        const note = $('nvStep3Note');
        if (note) note.textContent = visitType() === 'treatment' ? 'بۆ ئیش پێویست نییە' : 'تەنها بۆ پشکنین';
        const need = missing(step);
        const next = $('nvNext');
        if (next) { next.hidden = step >= last; next.disabled = need.length > 0; }
        if ($('nvBack')) $('nvBack').hidden = step === 1;
        ['nvBtnSave', 'nvBtnSaveSend'].forEach(id => { if ($(id)) $(id).hidden = step !== last; });
        if ($('nvNeed')) $('nvNeed').textContent = need.length ? 'پێویستە: ' + need.map(x => x[1]).join('، ') : '';
    }

    function go(n, focus) {
        const last = lastStep();
        if (n < 1 || n > last) return;
        if (n > step) { const bad = firstInvalid(n - 1); if (bad) { step = bad; render(); focusIn(bad); return; } }
        step = n;
        render();
        window.scrollTo({ top: 0 });
        if (focus !== false) focusIn(n);
    }

    document.addEventListener('click', e => {
        const b = e.target.closest('#nvSteps .nv-step');
        if (b && !b.classList.contains('is-off')) go(Number(b.dataset.go));
    });
    $('nvNext') && $('nvNext').addEventListener('click', () => go(step + 1));
    $('nvBack') && $('nvBack').addEventListener('click', () => go(step - 1));
    ['nvBtnSave', 'nvBtnSaveSend'].forEach(id => $(id) && $(id).addEventListener('click', () => { saveClicked = true; }));

    form.addEventListener('submit', e => {
        const wantsSave = saveClicked;
        saveClicked = false;
        if (!wantsSave) {           // Enter in a field: on to the next step
            e.preventDefault();
            if (step < lastStep() && !missing(step).length) go(step + 1);
            else if (step === lastStep() && !firstInvalid(lastStep())) { saveClicked = true; $('nvBtnSave').click(); }
            else focusIn(step);
            return;
        }
        const bad = firstInvalid(lastStep());
        if (bad) { e.preventDefault(); go(bad); }
    });

    // a patient picked from the search fills the fields without input events
    form.addEventListener('input', render);
    form.addEventListener('change', render);
    setInterval(render, 800);
    document.addEventListener('DOMContentLoaded', render);
    render();

    return {
        readyToSave() { const bad = firstInvalid(lastStep()); if (bad) { go(bad); return false; } return true; },
        saving() { saveClicked = true; },
        go: go
    };
})();

// Reception and patient choices both name the receiving treatment doctor.
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

// Toggle treated reason field when "no" is selected
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

// Show 'زانیاری سەردان بۆ پشکنین' only when visit_type=checkup; hide when=treatment.
// A treatment visit hides that whole card — including both doctor pickers — so it
// gets its own 'دکتۆری ئیش' box instead; without it the visit would be saved with
// no doctor at all and no doctor could see it in visits.php.
function toggleCheckupSection() {
    var section = document.getElementById('checkupSection');
    var arrival = document.getElementById('arrival_time');
    var priceGroup = document.getElementById('checkupPriceGroup');
    var priceInput = document.getElementById('price');
    var patientStatusGroup = document.getElementById('patientStatusGroup');
    var treatmentDoctorGroup = document.getElementById('treatmentDoctorGroup');
    var treatmentDoctor = document.getElementById('treatment_doctor_id');
    var oldStatus = document.querySelector('input[name="patient_status"][value="old"]');
    if (!section) return;
    var radios = document.getElementsByName('visit_type');
    var value = '';
    for (var i = 0; i < radios.length; i++) {
        if (radios[i].checked) { value = radios[i].value; break; }
    }
    if (value === 'treatment') {
        section.style.display = 'none';
        if (priceGroup) priceGroup.style.display = 'none';
        if (priceInput) priceInput.value = '0';
        if (arrival) arrival.removeAttribute('required');
        if (oldStatus) oldStatus.checked = true;
        if (patientStatusGroup) patientStatusGroup.style.display = 'none';
        if (treatmentDoctorGroup) treatmentDoctorGroup.style.display = '';
    } else {
        // 'checkup' (default) or any other state still shows the section
        section.style.display = '';
        if (priceGroup) priceGroup.style.display = '';
        if (arrival) arrival.setAttribute('required', 'required');
        if (patientStatusGroup) patientStatusGroup.style.display = '';
        if (treatmentDoctorGroup) treatmentDoctorGroup.style.display = 'none';
        if (treatmentDoctor) treatmentDoctor.value = '';   // never post a stale pick
    }
}

// Patient medical conditions cards (from patients/add.php)
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
    const profilePreviewDiv = document.getElementById('profile_image_preview');
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
        if (!profilePreviewDiv || !profilePreviewLink) return;
        if (path) {
            profilePreviewLink.href = '<?= APP_URL ?>/' + path;
            profilePreviewDiv.style.display = 'block';
        } else {
            profilePreviewLink.href = '#';
            profilePreviewDiv.style.display = 'none';
        }
    }

    function setFieldValue(id, value) {
        const el = document.getElementById(id);
        if (!el) return;
        const v = value ?? '';
        el.value = v;
        // If element is a <select> and the given value isn't an existing option,
        // append it so it stays selected (handles legacy free-text city names).
        if (el.tagName === 'SELECT' && v !== '' && el.value !== v) {
            const opt = document.createElement('option');
            opt.value = v;
            opt.textContent = v;
            opt.selected = true;
            el.appendChild(opt);
        }
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
        if (window.placeShow) placeShow(patient.location_id || null, patient.city ?? '', patient.area ?? '');
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
        if (window.placeShow) placeShow(null, '', '');
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
            // Live dropdown rows are slim — fetch full data (incl. medical conditions) on click
            item.addEventListener('click', () => selectPatientFull(p));
            resultsBox.appendChild(item);
        });

        resultsBox.style.display = 'block';
    }

    function searchPatients(term) {
        // slim=1 → backend skips medical_conditions for the dropdown, big speed win
        fetch(`<?= APP_URL ?>/ajax/patient_search.php?q=${encodeURIComponent(term)}&limit=5&slim=1`, {
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

    // Fetch full patient (incl. medical conditions) when a search row is clicked.
    function selectPatientFull(patientStub) {
        if (!patientStub || !patientStub.id) return;
        fetch(`<?= APP_URL ?>/ajax/patient_search.php?id=${encodeURIComponent(patientStub.id)}`, {
            headers: { 'Accept': 'application/json' }
        })
        .then(res => res.json())
        .then(data => {
            const full = (data && data.success && Array.isArray(data.results) && data.results[0]) ? data.results[0] : patientStub;
            selectPatient(full);
        })
        .catch(() => selectPatient(patientStub));
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', clearSelection);
    }

    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const term = (searchInput.value || '').trim();

            if (term.length < 1) {
                hideResults();
                return;
            }

            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => searchPatients(term), 180);
        });

        searchInput.addEventListener('focus', function() {
            const term = (searchInput.value || '').trim();
            if (term.length >= 1 && resultsBox && resultsBox.innerHTML.trim() !== '') {
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

    // Full Name Autocomplete
    const fnInput = document.getElementById('full_name');
    const fnBox = document.getElementById('full_name_suggestions');
    if (fnInput && fnBox) {
        let fnTimer = null;

        function hideFnResults() {
            fnBox.style.display = 'none';
            fnBox.innerHTML = '';
        }

        function renderFnResults(results) {
            fnBox.innerHTML = '';
            if (!Array.isArray(results) || results.length === 0) {
                hideFnResults();
                return;
            }
            results.forEach(p => {
                const item = document.createElement('div');
                item.className = 'search-item';
                item.innerHTML = `<span class="name">${escapeHtml(p.full_name)}${p.patient_code ? ' <span style="font-weight:600;color:#6e6e73;">(${escapeHtml(p.patient_code)})</span>' : ''}</span><span class="phone">${escapeHtml(p.phone)}</span>`;
                item.addEventListener('mousedown', function(e) {
                    e.preventDefault();
                    selectPatient(p);
                    hideFnResults();
                });
                fnBox.appendChild(item);
            });
            fnBox.style.display = 'block';
        }

        function searchFn(term, autoSelect) {
            fetch(`<?= APP_URL ?>/ajax/patient_search.php?q=${encodeURIComponent(term)}`, { headers: { 'Accept': 'application/json' } })
                .then(r => r.json())
                .then(data => {
                    const results = (data && data.success) ? (data.results || []) : [];
                    if (autoSelect) {
                        const exact = results.find(p => p.full_name.trim().toLowerCase() === term.toLowerCase());
                        if (exact) {
                            selectPatient(exact);
                            hideFnResults();
                            return;
                        }
                    }
                    renderFnResults(results);
                })
                .catch(() => hideFnResults());
        }

        fnInput.addEventListener('input', function() {
            const term = (fnInput.value || '').trim();
            if (term.length < 2) { hideFnResults(); return; }
            clearTimeout(fnTimer);
            fnTimer = setTimeout(function() { searchFn(term, false); }, 250);
        });

        fnInput.addEventListener('paste', function() {
            setTimeout(function() {
                const term = (fnInput.value || '').trim();
                if (term.length < 2) return;
                clearTimeout(fnTimer);
                searchFn(term, true);
            }, 50);
        });

        fnInput.addEventListener('drop', function() {
            setTimeout(function() {
                const term = (fnInput.value || '').trim();
                if (term.length < 2) return;
                clearTimeout(fnTimer);
                searchFn(term, true);
            }, 50);
        });

        fnInput.addEventListener('blur', function() {
            setTimeout(hideFnResults, 150);
        });

        document.addEventListener('click', function(e) {
            if (e.target !== fnInput && !fnBox.contains(e.target)) hideFnResults();
        });
    }
});

// Appointment Modal Functions — mirrors appointments.php exactly
var savedPatientId    = <?php echo json_encode($saved_patient_id); ?>;
var savedPatientName  = <?php echo json_encode($saved_patient_name); ?>;
var savedVisitType    = <?php echo json_encode($saved_visit_type); ?>;
var savedWorkTypeId   = <?php echo json_encode($saved_work_type_id); ?>;
var savedDoctorUserId = <?php echo json_encode($saved_doctor_user_id); ?>;
var savedOpenWaitingModal = <?php echo json_encode($saved_open_waiting_modal); ?>;
var aptExistingBookings = [];
var aptSelectedTime = '';

function clearNewVisitSuccessUrl() {
    window.history.replaceState({}, document.title, '<?= APP_URL ?>/new_visit.php' + (NV_EMBED ? '?embed=1' : ''));
    // In the dialog, closing the after-save choice ends the job: the parent
    // closes the dialog and reloads, so the new visit shows in its list.
    if (NV_EMBED && savedPatientId) nvParent({ action: 'saved' });
}

function showPostVisitChoiceModal() {
    var modal = document.getElementById('postVisitChoiceModal');
    if (!modal || !savedPatientId) return;
    var nameEl = document.getElementById('post-visit-patient-name');
    if (nameEl) nameEl.textContent = savedPatientName || '';
    modal.classList.add('open');
}

function closePostVisitChoiceModal() {
    var modal = document.getElementById('postVisitChoiceModal');
    if (modal) modal.classList.remove('open');
    clearNewVisitSuccessUrl();
}

function choosePostVisitAppointment() {
    var modal = document.getElementById('postVisitChoiceModal');
    if (modal) modal.classList.remove('open');
    showAppointmentModal();
}

function choosePostVisitWaitingRoom() {
    var modal = document.getElementById('postVisitChoiceModal');
    if (modal) modal.classList.remove('open');
    showNewVisitWaitingModal();
}

function showAppointmentModal() {
    var modal = document.getElementById('appointmentModal');
    if (!modal || !savedPatientId) return;
    document.getElementById('apt_patient_id').value = savedPatientId;
    document.getElementById('modal-patient-name').textContent = savedPatientName || '';
    modal.classList.add('open');
    setTimeout(refreshAptSlots, 150);
}

function closeAppointmentModal() {
    var modal = document.getElementById('appointmentModal');
    if (modal) modal.classList.remove('open');
    clearNewVisitSuccessUrl();
}

function showNewVisitWaitingModal() {
    var modal = document.getElementById('newVisitWaitingModal');
    if (!modal || !savedPatientId) return;

    document.getElementById('nv_wr_patient_id').value = savedPatientId;
    document.getElementById('nv_wr_patient_name').textContent = savedPatientName || '';
    document.getElementById('nv_wr_notes').value = '';

    // Inherit assigned doctor from the visit if the dropdown contains the matching user
    var docSel = document.getElementById('nv_wr_doctor_id');
    docSel.value = '';
    if (savedDoctorUserId) {
        var docMatch = docSel.querySelector('option[value="' + savedDoctorUserId + '"]');
        if (docMatch) docSel.value = String(savedDoctorUserId);
    }

    // Inherit visit_type from the just-created visit (defaults to 'checkup' if missing/invalid)
    var inheritedType = (savedVisitType === 'checkup' || savedVisitType === 'treatment') ? savedVisitType : 'checkup';
    document.getElementById('nv_wr_visit_type').value = inheritedType;

    // Inherit work_type_id from the visit if the dropdown contains it
    var wtSel = document.getElementById('nv_wr_work_type_id');
    wtSel.value = '';
    if (savedWorkTypeId) {
        var match = wtSel.querySelector('option[value="' + savedWorkTypeId + '"]');
        if (match) wtSel.value = String(savedWorkTypeId);
    }

    var priority = document.querySelector('input[name="nv_wr_priority"][value="0"]');
    if (priority) priority.checked = true;

    modal.classList.add('open');
}

function closeNewVisitWaitingModal() {
    var modal = document.getElementById('newVisitWaitingModal');
    if (modal) modal.classList.remove('open');
    clearNewVisitSuccessUrl();
}

function submitNewVisitWaitingRoom() {
    var patientId = document.getElementById('nv_wr_patient_id').value;
    var doctorId = document.getElementById('nv_wr_doctor_id').value;
    var workTypeId = document.getElementById('nv_wr_work_type_id').value;
    var visitType = document.getElementById('nv_wr_visit_type').value;
    var notes = document.getElementById('nv_wr_notes').value;
    var priorityInput = document.querySelector('input[name="nv_wr_priority"]:checked');
    var btn = document.getElementById('nv_wr_submit_btn');

    if (!doctorId) {
        Swal.fire({ icon: 'warning', title: 'دکتۆر دیاری بکە', confirmButtonText: 'باشە' });
        return false;
    }

    var fd = new FormData();
    fd.append('patient_id', patientId);
    fd.append('doctor_id', doctorId);
    fd.append('work_type_id', workTypeId);
    fd.append('visit_type', visitType);
    fd.append('priority', priorityInput ? priorityInput.value : '0');
    fd.append('notes', notes);

    var oldHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> دەنێردرێت...';

    fetch('<?= APP_URL ?>/ajax/add_to_waiting_room.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'سەرکەوتوو!',
                    text: data.message || 'نەخۆش نێردرا بۆ ژووری چاوەڕوانی',
                    showConfirmButton: false,
                    timer: 1400
                }).then(function() {
                    nvGo('<?= APP_URL ?>/waiting-room.php?added=1');
                });
                return;
            }

            Swal.fire({
                icon: data.already_in_waiting ? 'info' : 'error',
                title: data.already_in_waiting ? 'پێشتر لە ژووری چاوەڕوانیدایە' : 'هەڵە',
                text: data.error || 'هەڵەیەک ڕوویدا',
                confirmButtonText: 'باشە'
            });
        })
        .catch(function(error) {
            Swal.fire({ icon: 'error', title: 'هەڵە', text: 'کێشەی پەیوەندی: ' + error.message, confirmButtonText: 'باشە' });
        })
        .finally(function() {
            btn.disabled = false;
            btn.innerHTML = oldHtml;
        });

    return false;
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
    var summaryEl    = document.getElementById('apt_summary');

    if (!container) return;
    aptSelectedTime = '';
    if (summaryEl) summaryEl.style.display = 'none';

    if (!doctorId || !date) {
        container.innerHTML = '<div class="no-working-day"><i class="fas fa-exclamation-circle"></i> تکایە دکتۆر و بەروار هەڵبژێرە</div>';
        if (existingSec) existingSec.style.display = 'none';
        return;
    }

    container.innerHTML = '<div class="slots-loading"><i class="fas fa-spinner fa-spin"></i> چاوەڕوان بە...</div>';

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
                    return '<div class="existing-booking-item">' +
                           '<i class="fas fa-clock"></i>' +
                           '<span>' + b.start_time + ' - ' + b.end_time + '</span>' +
                           '<span class="patient-name" title="' + (b.patient_name || '') + '">' + (b.patient_name || 'مەوعید') + '</span>' +
                           '</div>';
                }).join('');
            } else {
                existingSec.style.display = 'block';
                existingList.innerHTML = '<div class="no-bookings-message"><i class="fas fa-check-circle"></i> هیچ مەوعیدێک نییە</div>';
            }
        }

        if (!slotsResult.success) {
            container.innerHTML = '<div class="no-working-day"><i class="fas fa-exclamation-circle"></i> ' + (slotsResult.message || 'هەڵەیەک ڕوویدا') + '</div>';
            return;
        }
        var data = slotsResult.data;
        if (!data.working) {
            container.innerHTML = '<div class="no-working-day"><i class="fas fa-bed"></i> ئەم ڕۆژە ڕۆژی پشووە</div>';
            return;
        }
        if (!data.slots.length) {
            container.innerHTML = '<div class="no-working-day"><i class="fas fa-calendar-times"></i> هیچ کاتێک بەردەست نییە</div>';
            return;
        }

        var html = '<div class="smart-time-picker-grid">';
        data.slots.forEach(function(slot) {
            var cls = slot.status === 'available' ? '' : slot.status;
            var click = (slot.status === 'available' || slot.status === 'booked') ? 'onclick="selectAptSlot(\'' + slot.time + '\')"' : '';
            var title = slot.info ? 'title="' + slot.info + '"' : '';
            html += '<div class="smart-time-slot ' + cls + '" ' + click + ' ' + title + '>' + slot.time + '</div>';
        });
        html += '</div>';
        container.innerHTML = html;
    } catch (err) {
        console.error(err);
        container.innerHTML = '<div class="no-working-day"><i class="fas fa-exclamation-triangle"></i> هەڵەیەک ڕوویدا</div>';
    }
}

function selectAptSlot(time) {
    aptSelectedTime = time;
    var duration = parseInt(document.getElementById('apt_duration').value) || 30;
    var endTime = addMinToTime(time, duration);
    document.getElementById('apt_end_time').value = endTime;

    document.querySelectorAll('#apt_time_grid .smart-time-slot').forEach(function(s) {
        s.classList.toggle('selected', s.textContent.trim() === time);
    });

    // Update booking summary
    var summary = document.getElementById('apt_summary');
    if (summary) {
        document.getElementById('apt_sum_date').textContent     = document.getElementById('apt_date').value;
        document.getElementById('apt_sum_start').textContent    = time;
        document.getElementById('apt_sum_end').textContent      = endTime;
        var hrs = Math.floor(duration / 60), mns = duration % 60;
        var durTxt;
        if (duration >= 60) {
            durTxt = mns > 0 ? (hrs + ' کاتژمێر و ' + mns + ' خولەک') : (hrs + ' کاتژمێر');
        } else {
            durTxt = duration + ' خولەک';
        }
        document.getElementById('apt_sum_duration').textContent = durTxt;
        summary.style.display = 'block';
    }
}

function toggleAptNotes() {
    var toggle  = document.getElementById('apt_notes_toggle');
    var section = document.getElementById('apt_notes_section');
    if (!toggle || !section) return;
    toggle.classList.toggle('open');
    section.classList.toggle('open');
}

// ── «جۆری ئیش» as cards, each with its icon (assets/js/treatment-icons.js) ──
// The most used come first; the rest are behind «هەموو جۆرەکان» or the search.
// A name typed twice («ددانی شكایه‌» ×2, «کێشەی هەیە» / «كیشه‌ی هه‌یه‌») is one
// card, its more used twin.
var WORK_TYPES = <?php echo json_encode($work_types_list ?? [], JSON_UNESCAPED_UNICODE); ?>;
var WT_FIRST = 12;            // cards before «هەموو جۆرەکان»
var wtAll = false;

function wtFold(s) {
    s = String(s || '').replace(/\s+/g, ' ').trim();
    return window.TreatmentIcons ? TreatmentIcons.fold(s) : s.toLowerCase();
}
function wtIconKey(w) {
    if (!window.TreatmentIcons) return '';
    return TreatmentIcons.has(w.icon) ? w.icon : TreatmentIcons.guessWork(w.name);
}
/** One card per name, most used first: {id, name, icon, uses, ids}. */
function wtCardList() {
    var byName = {}, out = [];
    WORK_TYPES.forEach(function (w) {
        var k = wtFold(w.name), c = byName[k], n = Number(w.uses) || 0;
        if (!c) { c = byName[k] = { uses: 0, best: -1, ids: [], at: out.length }; out.push(c); }
        c.ids.push(Number(w.id));
        c.uses += n;
        if (n > c.best) { c.best = n; c.id = Number(w.id); c.name = w.name; c.icon = w.icon; }
    });
    return out.sort(function (a, b) { return b.uses - a.uses || a.at - b.at; });
}

function wtRender() {
    var box = document.getElementById('wtCards'), more = document.getElementById('wtMore');
    if (!box) return;
    var typed = (document.getElementById('wtSearch').value || '').trim(), q = wtFold(typed);
    var sel = Number(document.getElementById('work_type_id').value) || 0;
    var list = wtCardList(), shown;
    if (q) {
        shown = list.filter(function (c) { return wtFold(c.name).indexOf(q) !== -1; });
    } else if (wtAll) {
        shown = list;
    } else {
        shown = list.slice(0, WT_FIRST);
        var picked = list.filter(function (c) { return c.ids.indexOf(sel) !== -1; })[0];
        if (picked && shown.indexOf(picked) === -1) shown.push(picked);   // the chosen one stays in sight
    }
    box.innerHTML = '';
    shown.forEach(function (c) {
        var on = c.ids.indexOf(sel) !== -1;
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'wt-card' + (on ? ' is-on' : '');
        b.setAttribute('role', 'radio');
        b.setAttribute('aria-checked', on ? 'true' : 'false');
        b.title = c.name;
        b.innerHTML = '<span class="wt-ico">' + (window.TreatmentIcons ? TreatmentIcons.svg(wtIconKey(c)) : '') + '</span><span class="wt-name"></span>';
        b.querySelector('.wt-name').textContent = c.name;
        b.addEventListener('click', function () { wtPick(on ? 0 : c.id); });   // a second click lets it go
        box.appendChild(b);
    });
    // the last card adds one — what was searched for, when nothing has that name
    var add = document.createElement('button');
    add.type = 'button';
    add.className = 'wt-card wt-card--add';
    add.innerHTML = '<span class="wt-ico"><i class="fas fa-plus"></i></span><span class="wt-name"></span>';
    add.querySelector('.wt-name').textContent = typed && !shown.length ? '«' + typed + '» زیاد بکە' : 'ئیشی نوێ';
    add.addEventListener('click', function () { openAddWorkTypeNv(shown.length ? '' : typed); });
    box.appendChild(add);
    if (more) {
        more.hidden = !!q || list.length <= WT_FIRST;
        more.innerHTML = wtAll ? '<i class="fas fa-chevron-up"></i> کەمتر' : '<i class="fas fa-chevron-down"></i> هەموو جۆرەکان (' + list.length + ')';
    }
}

function wtPick(id) {
    document.getElementById('work_type_id').value = id ? id : '';
    wtRender();
}

(function () {
    var search = document.getElementById('wtSearch'), more = document.getElementById('wtMore');
    if (!search) return;
    search.addEventListener('input', wtRender);
    search.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter') {            // the first card found, not the next step
            ev.preventDefault();
            var first = document.querySelector('#wtCards .wt-card:not(.wt-card--add)');
            if (first && search.value.trim()) { first.click(); search.value = ''; wtRender(); }
        } else if (ev.key === 'Escape' && search.value) {
            ev.preventDefault();
            search.value = '';
            wtRender();
        }
    });
    if (more) more.addEventListener('click', function () { wtAll = !wtAll; wtRender(); });
    wtRender();
})();

// ── Add a work type: its name and its icon — the examples fill both ──
// (spelled as the clinic's own types are, so one that is there is chosen, not made twice)
var WT_EXAMPLES = [
    ['ئازاری هەیە', 'pain'], ['ددانی شکایە', 'broken'], ['ددانی شل بووە', 'loose'], ['خوێنی ڕاناوەستێ', 'bleeding'],
    ['هەوکردنی پدو', 'swelling'], ['هەستیارە بەسارد و گەرمی', 'sensitive'], ['ددانی عەقلی', 'wisdom'], ['کیفی شل بووە', 'crown'],
    ['قالب گرتن', 'impression'], ['ریتێنەر', 'retainer'], ['مراجعە', 'followup'], ['پسیارکردن', 'consult']
];
var wtIcons = null;           // the dialog's icon chooser, made on first open
var wtIconChosen = false;     // picked by hand: the name no longer moves it

function wtDlgErr(msg) {
    var e = document.getElementById('addWorkTypeErrNv');
    e.textContent = msg || '';
    e.style.display = msg ? 'block' : 'none';
    if (!msg) document.getElementById('newWorkTypeIconsNv').classList.remove('ti-missing');
}

function wtIconsInit() {
    if (wtIcons || !window.TreatmentIcons) return;
    wtIcons = TreatmentIcons.chooser(document.getElementById('newWorkTypeIconsNv'), {
        set: 'work',
        onChange: function () { wtIconChosen = true; wtDlgErr(''); }
    });
    var ex = document.getElementById('wtExamples');
    WT_EXAMPLES.forEach(function (e) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'wt-ex';
        b.innerHTML = '<span class="wt-ex-ico">' + TreatmentIcons.svg(e[1]) + '</span><span></span>';
        b.lastChild.textContent = e[0];
        b.addEventListener('click', function () {
            var i = document.getElementById('newWorkTypeNameNv');
            i.value = e[0];
            wtIcons.set(e[1]);
            wtIconChosen = true;
            wtDlgErr('');
            i.focus();
        });
        ex.appendChild(b);
    });
    // until an icon is picked by hand, the name picks one
    document.getElementById('newWorkTypeNameNv').addEventListener('input', function () {
        wtDlgErr('');
        if (wtIconChosen) return;
        var k = TreatmentIcons.guessWork(this.value);
        wtIcons.set(this.value.trim() && k !== 'other' ? k : '');
    });
}

function openAddWorkTypeNv(name) {
    var d = document.getElementById('addWorkTypeDialogNv');
    var i = document.getElementById('newWorkTypeNameNv');
    wtIconsInit();
    wtIconChosen = false;
    if (i) i.value = typeof name === 'string' ? name : '';
    if (wtIcons) {
        var k = i && i.value ? TreatmentIcons.guessWork(i.value) : '';
        wtIcons.set(k && k !== 'other' ? k : '');
    }
    wtDlgErr('');
    if (d) d.classList.add('open');
    setTimeout(function() { i && i.focus(); }, 50);
}
function closeAddWorkTypeNv() {
    var d = document.getElementById('addWorkTypeDialogNv');
    if (d) d.classList.remove('open');
}
function submitNewWorkTypeNv() {
    var i = document.getElementById('newWorkTypeNameNv');
    var e = document.getElementById('addWorkTypeErrNv');
    var name = (i.value || '').trim();
    var icon = wtIcons ? wtIcons.get() : '';
    if (!name) { wtDlgErr('تکایە ناوی ئیش بنووسە'); return; }
    if (wtIcons && !icon) { wtIcons.missing(); wtDlgErr('تکایە ئایکۆنێک هەڵبژێرە'); return; }
    // a name that is there already is chosen, not made twice
    var same = wtCardList().filter(function (c) { return wtFold(c.name) === wtFold(name); })[0];
    if (same) {
        document.getElementById('wtSearch').value = '';
        wtPick(same.id);
        closeAddWorkTypeNv();
        return;
    }
    fetch('<?= APP_URL ?>/api/work-types.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'add', work_name: name, icon: icon, scope: 'new_visit' })
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (!d.success) { e.textContent = d.message || 'هەڵە'; e.style.display = 'block'; return; }
        // The two modal dropdowns are still plain <select>s
        ['apt_work_type', 'nv_wr_work_type_id'].forEach(function(selId) {
            var sel = document.getElementById(selId);
            if (!sel) return;
            var opt = document.createElement('option');
            opt.value = d.id; opt.textContent = d.work_name; opt.selected = true;
            sel.appendChild(opt);
        });
        // The main form's cards
        if (!WORK_TYPES.some(function(w) { return String(w.id) === String(d.id); })) {
            WORK_TYPES.push({ id: Number(d.id), name: d.work_name, icon: d.icon, uses: 0 });
        }
        document.getElementById('wtSearch').value = '';
        wtPick(d.id);
        closeAddWorkTypeNv();
    })
    .catch(function(err) { e.textContent = 'کێشەی پەیوەندی: ' + err.message; e.style.display = 'block'; });
}

// ── شوێن: پارێزگا → ئیدارە → شار → گەڕەک ──
var LOCATIONS = <?php echo json_encode($locations_all, JSON_UNESCAPED_UNICODE); ?>;
function locById(id) { for (var i = 0; i < LOCATIONS.length; i++) if (LOCATIONS[i].id === Number(id)) return LOCATIONS[i]; return null; }
function locKids(parentId, level) {
    return LOCATIONS.filter(function (l) { return l.parent_id === (parentId == null ? null : Number(parentId)) && (!level || l.level === level); });
}
/** A گەڕەک with everything above it: {id, area, city, region, province, path}. */
function locPlace(areaId) {
    var a = locById(areaId); if (!a) return null;
    var c = locById(a.parent_id) || {}, up = locById(c.parent_id) || {};
    var region = up.level === 'region' ? up : null, prov = region ? (locById(up.parent_id) || {}) : up;
    return { id: a.id, area: a.name, city: c.name || '', region: region ? region.name : '', province: prov.name || '',
             path: [c.name, region && region.name, prov.name].filter(Boolean).join(' · ') };
}
/** Every گەڕەک in the tree's own order: پارێزگا → ئیدارە → شار → گەڕەک. */
function locPlaces() {
    var out = [];
    (function walk(parentId) {
        locKids(parentId).forEach(function (l) {
            if (l.level === 'area') out.push(locPlace(l.id)); else walk(l.id);
        });
    })(null);
    return out;
}

// ── the dialog: all three names (پارێزگا، شار، گەڕەک) required, ئیدارە if there is one ──
function locFill(listId, names) {
    var dl = document.getElementById(listId); if (!dl) return;
    dl.innerHTML = names.map(function (n) { return '<option value="' + String(n).replace(/"/g, '&quot;') + '">'; }).join('');
}
function locByName(parentId, name, levels) {
    name = String(name || '').trim();
    return LOCATIONS.filter(function (l) { return l.parent_id === parentId && l.name === name && (!levels || levels.indexOf(l.level) !== -1); })[0] || null;
}
function locRefreshLists() {
    var prov = locByName(null, document.getElementById('newPlaceProvince').value, ['province']);
    var region = prov ? locByName(prov.id, document.getElementById('newPlaceRegion').value, ['region']) : null;
    var cityParent = region || prov;
    var city = cityParent ? locByName(cityParent.id, document.getElementById('newPlaceCity').value, ['city']) : null;
    locFill('locProvinceList', locKids(null, 'province').map(function (l) { return l.name; }));
    locFill('locRegionList', prov ? locKids(prov.id, 'region').map(function (l) { return l.name; }) : []);
    locFill('locCityList', cityParent ? locKids(cityParent.id, 'city').map(function (l) { return l.name; }) : []);
    locFill('locAreaList', city ? locKids(city.id, 'area').map(function (l) { return l.name; }) : []);
}
function openAddCityDialog(areaGuess) {
    var d = document.getElementById('addCityDialog');
    var e = document.getElementById('addCityErr');
    ['newPlaceProvince', 'newPlaceRegion', 'newPlaceCity', 'newPlaceArea'].forEach(function (id) { document.getElementById(id).value = ''; });
    if (typeof areaGuess === 'string') document.getElementById('newPlaceArea').value = areaGuess;
    // the usual case starts filled in: هەولێر › ئیدارەی سەربەخۆی سۆران
    var first = locKids(null, 'province')[0];
    if (first) {
        document.getElementById('newPlaceProvince').value = first.name;
        var reg = locKids(first.id, 'region')[0];
        if (reg) document.getElementById('newPlaceRegion').value = reg.name;
    }
    locRefreshLists();
    if (e) { e.style.display = 'none'; e.textContent = ''; }
    if (d) d.classList.add('open');
    setTimeout(function () { document.getElementById('newPlaceCity').focus(); }, 50);
}
['newPlaceProvince', 'newPlaceRegion', 'newPlaceCity'].forEach(function (id) {
    document.addEventListener('input', function (ev) { if (ev.target && ev.target.id === id) locRefreshLists(); });
});
function closeAddCityDialog() {
    var d = document.getElementById('addCityDialog');
    if (d) d.classList.remove('open');
}
function submitNewCity() {
    var e = document.getElementById('addCityErr');
    var val = function (id) { return (document.getElementById(id).value || '').trim(); };
    var body = { action: 'add', province: val('newPlaceProvince'), region: val('newPlaceRegion'), city: val('newPlaceCity'), area: val('newPlaceArea') };
    var missing = [];
    if (!body.province) missing.push('پارێزگا');
    if (!body.city) missing.push('شار');
    if (!body.area) missing.push('گەڕەک');
    if (missing.length) { e.textContent = 'تکایە ' + missing.join('، ') + ' بنووسە'; e.style.display = 'block'; return; }
    fetch('<?= APP_URL ?>/api/locations.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
    })
    .then(function (r) { return r.json(); })
    .then(function (d) {
        if (!d.success) { e.textContent = d.message || 'هەڵە'; e.style.display = 'block'; return; }
        (d.chain || []).forEach(function (l) { if (!locById(l.id)) LOCATIONS.push({ id: Number(l.id), parent_id: l.parent_id == null ? null : Number(l.parent_id), level: l.level, name: l.name }); });
        cityComboPick(Number(d.id));
        closeAddCityDialog();
    })
    .catch(function (err) { e.textContent = 'کێشەی پەیوەندی: ' + err.message; e.style.display = 'block'; });
}

// ── the field: one search over every گەڕەک, grouped by شار ──
function cityComboRender(filter) {
    var dd = document.getElementById('cityComboDropdown');
    if (!dd) return;
    dd.innerHTML = '';
    var f = String(filter || '').trim().toLowerCase();
    var groups = {}, order = [];
    locPlaces().forEach(function (p) {
        var hay = (p.area + ' ' + p.city + ' ' + p.region + ' ' + p.province).toLowerCase();
        if (f && hay.indexOf(f) === -1) return;
        if (!groups[p.path]) { groups[p.path] = []; order.push(p.path); }
        groups[p.path].push(p);
    });
    order.forEach(function (path) {
        var g = document.createElement('div');
        g.className = 'city-combo-group';
        g.textContent = path;
        dd.appendChild(g);
        groups[path].forEach(function (p) {
            var it = document.createElement('div');
            it.className = 'city-combo-item';
            it.textContent = p.area;
            // mousedown (not click) so the input doesn't lose focus first
            it.addEventListener('mousedown', function (ev) { ev.preventDefault(); cityComboPick(p.id); });
            dd.appendChild(it);
        });
    });
    if (!order.length) {
        var em = document.createElement('div');
        em.className = 'city-combo-empty';
        em.textContent = 'هیچ شوێنێک نەدۆزرایەوە';
        dd.appendChild(em);
    }
    // not in the list yet: add it here, with its پارێزگا and شار
    var add = document.createElement('div');
    add.className = 'city-combo-add';
    add.innerHTML = '<i class="fas fa-plus"></i> ' + (f ? '«' + String(filter).trim().replace(/</g, '&lt;') + '» زیاد بکە وەک گەڕەک' : 'شوێنی نوێ زیاد بکە');
    add.addEventListener('mousedown', function (ev) {
        ev.preventDefault();
        dd.style.display = 'none';
        openAddCityDialog(String(filter || '').trim());
    });
    dd.appendChild(add);
}

function cityComboOpen() {
    var inp = document.getElementById('cityComboInput');
    cityComboRender(document.getElementById('location_id').value ? '' : (inp ? inp.value : ''));
    var dd = document.getElementById('cityComboDropdown');
    if (dd) dd.style.display = 'block';
}

function cityComboFilter() {
    var inp = document.getElementById('cityComboInput');
    var v = inp ? inp.value : '';
    // typed, not picked: kept as the city (as before), with no گەڕەک
    document.getElementById('city').value = v.trim();
    document.getElementById('area').value = '';
    document.getElementById('location_id').value = '';
    cityComboRender(v);
    var dd = document.getElementById('cityComboDropdown');
    if (dd) dd.style.display = 'block';
}

/** Pick a گەڕەک by id — or show a patient's saved place (placeShow). */
function cityComboPick(areaId) {
    var p = locPlace(areaId);
    if (!p) return;
    placeShow(p.id, p.city, p.area);
    var dd = document.getElementById('cityComboDropdown');
    if (dd) dd.style.display = 'none';
    var dirty = document.getElementById('patient_dirty');
    if (dirty) dirty.value = '1';
}
function placeShow(locationId, city, area) {
    var p = locationId ? locPlace(locationId) : null;
    document.getElementById('city').value = p ? p.city : (city || '');
    document.getElementById('area').value = p ? p.area : (area || '');
    document.getElementById('location_id').value = p ? p.id : '';
    var inp = document.getElementById('cityComboInput');
    if (inp) inp.value = p ? p.area + '، ' + p.city : [area, city].filter(Boolean).join('، ');
}

document.addEventListener('click', function(ev) {
    if (!(ev.target.closest && ev.target.closest('.city-combo'))) {
        var dd = document.getElementById('cityComboDropdown');
        if (dd) dd.style.display = 'none';
    }
});

function submitAppointment() {
    if (!aptSelectedTime) {
        Swal.fire({ icon: 'warning', title: 'کات هەڵنەبژێردراوە', text: 'تکایە کاتێک هەڵبژێرە', confirmButtonText: 'باشە' });
        return;
    }
    var doctorId = document.getElementById('apt_doctor_id').value;
    var date     = document.getElementById('apt_date').value;
    var duration = document.getElementById('apt_duration').value || 30;
    if (!doctorId || !date) {
        Swal.fire({ icon: 'warning', title: 'زانیاری کەم', text: 'تکایە دکتۆر و بەروار هەڵبژێرە', confirmButtonText: 'باشە' });
        return;
    }

    // Overlapping bookings are allowed by clinic policy — no overlap check

    var fd = new FormData();
    fd.append('patient_id',       savedPatientId);
    fd.append('doctor_id',        doctorId);
    fd.append('appointment_date', date);
    fd.append('appointment_time', aptSelectedTime);
    fd.append('duration',         duration);
    fd.append('type',             document.getElementById('apt_type').value);
    fd.append('work_type_id',     document.getElementById('apt_work_type').value);
    fd.append('notes',            document.getElementById('apt_notes').value);

    var btn = document.getElementById('apt_submit_btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> پاشەکەوت دەکرێت...';

    fetch('<?= APP_URL ?>/ajax/create_appointment.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.success) {
            Swal.fire({
                icon: 'success',
                title: 'سەرکەوتوو!',
                text: 'مەوعیدەکە بە سەرکەوتوویی تۆمارکرا',
                showConfirmButton: false,
                timer: 1500
            }).then(function() {
                nvGo('<?= APP_URL ?>/appointments.php?date=' + date + '&created=1');
            });
        } else {
            Swal.fire({ icon: 'error', title: 'هەڵە!', text: d.message || 'هەڵەیەک ڕوویدا', confirmButtonText: 'باشە' });
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-plus-circle"></i> زیادکردن بۆ مەوعیدەکان';
        }
    })
    .catch(function(e) {
        Swal.fire({ icon: 'error', title: 'هەڵە!', text: 'کێشەی پەیوەندی: ' + e.message, confirmButtonText: 'باشە' });
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-plus-circle"></i> زیادکردن بۆ مەوعیدەکان';
    });
}

// Show modal on page load if success with patient_id
document.addEventListener('DOMContentLoaded', function() {
    if (savedPatientId) {
        if (savedOpenWaitingModal) {
            showNewVisitWaitingModal();
        } else {
            showPostVisitChoiceModal();
        }
    }
});
</script>

<?php
// Close mysqli connection
$conn->close();

require_once __DIR__ . '/includes/footer.php';
?>
