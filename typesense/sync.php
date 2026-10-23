<?php
/**
 * Typesense Full Sync
 * Indexes all patients and visits from MySQL into Typesense.
 * Run after setup.php, and whenever you want a fresh re-index.
 * Access: admin only
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/typesense.php';
require_once __DIR__ . '/../classes/TypesenseClient.php';
require_once __DIR__ . '/../classes/Auth.php';

$auth = new Auth();
// Admin only — this rebuilds the whole search index.
if (!$auth->isLoggedIn() || !$auth->isAdmin()) {
    die('<b>Access denied.</b> Admin only.');
}

$ts = new TypesenseClient();
if (!$ts->isAvailable()) {
    die('<b>Typesense is not running.</b> Run setup.php first.');
}

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$conn->set_charset('utf8mb4');

$log    = [];
$errors = 0;

// ---------------------------------------------------------------
// Sync patients
// ---------------------------------------------------------------
$result = $conn->query("SELECT id, patient_code, full_name, phone, phone2 FROM patients ORDER BY id");
$docs   = [];

while ($row = $result->fetch_assoc()) {
    $docs[] = [
        'id'           => (string)$row['id'],
        'numeric_id'   => (int)$row['id'],
        'patient_code' => $row['patient_code'] ?? '',
        'full_name'    => $row['full_name'] ?? '',
        'phone'        => $row['phone'] ?? '',
        'phone2'       => $row['phone2'] ?? '',
    ];
}

if (!empty($docs)) {
    try {
        $response = $ts->importDocuments(TYPESENSE_PATIENTS_COLLECTION, $docs);
        $failed   = substr_count($response, '"success":false');
        $log[]    = "✅ Patients: indexed <b>" . count($docs) . "</b> records" . ($failed ? " (<span style='color:red'>$failed failed</span>)" : '');
    } catch (Exception $e) {
        $log[]  = "❌ Patients sync failed: " . htmlspecialchars($e->getMessage());
        $errors++;
    }
} else {
    $log[] = "ℹ️  Patients: table is empty.";
}

// ---------------------------------------------------------------
// Sync visits
// ---------------------------------------------------------------
$result = $conn->query("
    SELECT id, patient_name, mobile_number, visit_date,
           patient_status,
           COALESCE(assigned_doctor_id, 0)  AS assigned_doctor_id,
           COALESCE(requested_doctor_id, 0) AS requested_doctor_id
    FROM visits
    ORDER BY id
");
$docs = [];

while ($row = $result->fetch_assoc()) {
    $ts_val = (!empty($row['visit_date']) && $row['visit_date'] !== '0000-00-00')
        ? (int)strtotime($row['visit_date'])
        : 0;

    $docs[] = [
        'id'                  => (string)$row['id'],
        'numeric_id'          => (int)$row['id'],
        'patient_name'        => $row['patient_name'] ?? '',
        'mobile_number'       => $row['mobile_number'] ?? '',
        'visit_date_ts'       => $ts_val,
        'patient_status'      => $row['patient_status'] ?? 'new',
        'assigned_doctor_id'  => (int)$row['assigned_doctor_id'],
        'requested_doctor_id' => (int)$row['requested_doctor_id'],
    ];
}

if (!empty($docs)) {
    try {
        $response = $ts->importDocuments(TYPESENSE_VISITS_COLLECTION, $docs);
        $failed   = substr_count($response, '"success":false');
        $log[]    = "✅ Visits: indexed <b>" . count($docs) . "</b> records" . ($failed ? " (<span style='color:red'>$failed failed</span>)" : '');
    } catch (Exception $e) {
        $log[]  = "❌ Visits sync failed: " . htmlspecialchars($e->getMessage());
        $errors++;
    }
} else {
    $log[] = "ℹ️  Visits: table is empty.";
}

$conn->close();

echo '<link rel="stylesheet" href="' . APP_URL . '/assets/css/fonts.css">';
echo '<style>body{font-family:var(--app-font-family);padding:20px;} p{margin:4px 0;}</style>';
echo '<h3>Typesense Sync</h3>';
foreach ($log as $line) {
    echo "<p>$line</p>";
}
echo $errors === 0
    ? '<p><br>✔ Sync complete. Search is ready.</p>'
    : '<p><br>⚠ Sync finished with errors.</p>';
