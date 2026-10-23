<?php
/**
 * Patient Search AJAX Endpoint — optimized live search.
 *
 * Speed optimizations:
 *   - Persistent MySQL connection (p: prefix) to avoid TCP setup per keystroke.
 *   - Inline session auth check (no Auth class boot).
 *   - No SHOW TABLES probes — assume schema exists.
 *   - Medical conditions fetched in ONE batched IN(...) query (was N+1).
 *   - slim=1 mode skips conditions entirely for the dropdown listing.
 *   - Typesense /health result cached in storage/ for 60s, so a failed first
 *     attempt does not slow every subsequent keystroke.
 *
 * Query params:
 *   q       (string, required if id not given)
 *   id      (int, optional) — fetch one patient by id (full data + conditions)
 *   limit   (int, default 5, max 20)
 *   slim    ("1" → omit medical_conditions from response)
 */

require_once __DIR__ . '/../config/config.php';

// Cheap inline auth check
if (empty($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$id    = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$q     = trim($_GET['q'] ?? '');
$limit = isset($_GET['limit']) ? max(1, min(20, (int)$_GET['limit'])) : 5;
$slim  = isset($_GET['slim']) && $_GET['slim'] === '1';

// Doctor isolation: a doctor may only find patients they own or that reception
// referred to them. 0 = no restriction (admin / receptionist / accountant / xray).
$restrictDoctorId = (($_SESSION['role'] ?? '') === 'doctor') ? (int)($_SESSION['user_id'] ?? 0) : 0;

if ($id <= 0 && $q === '') {
    echo json_encode(['success' => true, 'results' => []]);
    exit;
}

// Persistent connection — pooled by PHP across requests, saves ~5-30ms each call
$conn = @new mysqli('p:' . DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}
$conn->set_charset('utf8mb4');

// ---------------------------------------------------------------
// Direct lookup by id (used after a result is clicked → full data)
// ---------------------------------------------------------------
if ($id > 0) {
    $stmt = $conn->prepare("
        SELECT id, patient_code, full_name, gender, date_of_birth,
               phone, phone2, address, city, occupation, blood_type,
               notes, profile_image
        FROM patients WHERE id = ? LIMIT 1
    ");
    $patient = null;
    if ($stmt) {
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $patient = $row;
        }
        $stmt->close();
    }
    if (!$patient) {
        echo json_encode(['success' => true, 'results' => []]);
        $conn->close();
        exit;
    }
    // A doctor may only open a patient they own or that reception referred to them.
    if ($restrictDoctorId && empty(dh_accessible_ids($conn, [$id], $restrictDoctorId))) {
        echo json_encode(['success' => true, 'results' => []]);
        $conn->close();
        exit;
    }
    if (!$slim) {
        $patient['medical_conditions'] = dh_load_conditions($conn, [$id])[$id] ?? [];
    }
    echo json_encode(['success' => true, 'results' => [$patient]]);
    $conn->close();
    exit;
}

// ---------------------------------------------------------------
// Step 1 — Typesense fast path (with 60s health-cache so dead host
// doesn't slow every keystroke)
// ---------------------------------------------------------------
$patient_ids = null; // null = fall back to MySQL LIKE

$ts_ok = dh_typesense_cached_ok(__DIR__ . '/../storage/typesense_status.json');
if ($ts_ok) {
    try {
        require_once __DIR__ . '/../config/typesense.php';
        require_once __DIR__ . '/../classes/TypesenseClient.php';
        $ts = new TypesenseClient(null, null, null, null, 2); // 2s upper bound
        $result = $ts->search(TYPESENSE_PATIENTS_COLLECTION, [
            'q'         => $q,
            'query_by'  => 'full_name,phone,phone2,patient_code',
            'per_page'  => $limit,
            'num_typos' => '1',
            'sort_by'   => 'numeric_id:asc',
        ]);
        $patient_ids = array_map(
            'intval',
            array_column(array_column($result['hits'] ?? [], 'document'), 'id')
        );
    } catch (Exception $e) {
        dh_typesense_mark_down(__DIR__ . '/../storage/typesense_status.json');
        $patient_ids = null;
    }
}

// ---------------------------------------------------------------
// Step 2 — Fetch patient rows
// ---------------------------------------------------------------
$patients = [];

if ($patient_ids !== null) {
    if (empty($patient_ids)) {
        echo json_encode(['success' => true, 'results' => []]);
        $conn->close();
        exit;
    }
    $ids_sql = implode(',', $patient_ids);
    $res = $conn->query("
        SELECT id, patient_code, full_name, gender, date_of_birth,
               phone, phone2, address, city, occupation, blood_type,
               notes, profile_image
        FROM patients
        WHERE id IN ($ids_sql)
        ORDER BY FIELD(id, $ids_sql)
        LIMIT $limit
    ");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $patients[] = $row;
        }
    }
} else {
    // MySQL fallback — LIKE search
    $term = '%' . $q . '%';
    $stmt = $conn->prepare("
        SELECT id, patient_code, full_name, gender, date_of_birth,
               phone, phone2, address, city, occupation, blood_type,
               notes, profile_image
        FROM patients
        WHERE full_name LIKE ? OR phone LIKE ? OR phone2 LIKE ?
        ORDER BY full_name
        LIMIT $limit
    ");
    if ($stmt) {
        $stmt->bind_param('sss', $term, $term, $term);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $patients[] = $row;
        }
        $stmt->close();
    }
}

// ---------------------------------------------------------------
// Step 2.5 — Doctor isolation: drop any patient the doctor is not
// allowed to see (not owned and not referred to them by reception).
// ---------------------------------------------------------------
if ($restrictDoctorId && !empty($patients)) {
    $allowed = array_flip(dh_accessible_ids($conn, array_column($patients, 'id'), $restrictDoctorId));
    $patients = array_values(array_filter($patients, fn($p) => isset($allowed[(int)$p['id']])));
}

// ---------------------------------------------------------------
// Step 3 — Attach medical conditions (single batched query)
// ---------------------------------------------------------------
if (!$slim && !empty($patients)) {
    $ids = array_column($patients, 'id');
    $byPid = dh_load_conditions($conn, $ids);
    foreach ($patients as &$p) {
        $p['medical_conditions'] = $byPid[(int)$p['id']] ?? [];
    }
    unset($p);
}

echo json_encode(['success' => true, 'results' => $patients]);
$conn->close();


// ===============================================================
// Helpers
// ===============================================================

/**
 * Given candidate patient ids, return the subset the doctor may see:
 * patients they own (a folder) or that reception referred to them
 * (an appointment or waiting-room entry). $doctorId is a trusted int.
 */
function dh_accessible_ids(mysqli $conn, array $ids, int $doctorId): array {
    if ($doctorId <= 0) return array_map('intval', $ids);
    $clean = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
    if (empty($clean)) return [];
    $in = implode(',', $clean);
    $d  = (int)$doctorId;
    $res = @$conn->query("
        SELECT p.id FROM patients p
        WHERE p.id IN ($in) AND (
              EXISTS (SELECT 1 FROM patient_folders pf WHERE pf.patient_id = p.id AND pf.doctor_id = $d)
           OR EXISTS (SELECT 1 FROM appointments a     WHERE a.patient_id  = p.id AND a.doctor_id  = $d)
           OR EXISTS (SELECT 1 FROM waiting_room wr     WHERE wr.patient_id = p.id AND wr.doctor_id = $d)
        )
    ");
    $ok = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) $ok[] = (int)$row['id'];
    }
    return $ok;
}

/**
 * Load medical conditions for the given patient IDs in a single query.
 * Returns: [patientId => [ ['type'=>..., 'details'=>..., 'severity'=>...], ... ]]
 */
function dh_load_conditions(mysqli $conn, array $ids): array {
    $out = [];
    if (empty($ids)) return $out;
    $cleanIds = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
    if (empty($cleanIds)) return $out;
    $placeholders = implode(',', $cleanIds);
    $res = @$conn->query("
        SELECT patient_id, condition_type, condition_details, severity
        FROM patient_medical_conditions
        WHERE patient_id IN ($placeholders)
    ");
    if (!$res) return $out;
    while ($row = $res->fetch_assoc()) {
        $pid = (int)$row['patient_id'];
        $out[$pid][] = [
            'type'     => $row['condition_type'],
            'details'  => $row['condition_details'] ?? '',
            'severity' => $row['severity'] ?? 'medium',
        ];
    }
    return $out;
}

/**
 * Returns true if Typesense was confirmed healthy within the last 60s OR if it
 * has never been probed yet. If a previous probe within 60s said it was down,
 * skip the network call entirely.
 */
function dh_typesense_cached_ok(string $statusFile): bool {
    if (!file_exists($statusFile)) {
        // First call ever — let it try once. If it fails the catch will mark down.
        return true;
    }
    $cached = @json_decode(@file_get_contents($statusFile), true);
    if (!is_array($cached)) return true;
    $age = time() - (int)($cached['t'] ?? 0);
    if ($age >= 60) return true;          // cache stale — try again
    return ($cached['ok'] ?? false) === true;
}

function dh_typesense_mark_down(string $statusFile): void {
    @file_put_contents(
        $statusFile,
        json_encode(['t' => time(), 'ok' => false]),
        LOCK_EX
    );
}
