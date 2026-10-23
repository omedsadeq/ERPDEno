<?php
/**
 * Typesense Collection Setup
 * Run once to create (or recreate) the search collections.
 * Access: admin only
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/typesense.php';
require_once __DIR__ . '/../classes/TypesenseClient.php';
require_once __DIR__ . '/../classes/Auth.php';

$auth = new Auth();
// Admin only — this drops and rebuilds the whole search index.
if (!$auth->isLoggedIn() || !$auth->isAdmin()) {
    die('<b>Access denied.</b> Admin only.');
}

$ts = new TypesenseClient();

if (!$ts->isAvailable()) {
    die('<b>Typesense is not running.</b><br>Start it with:<br><pre>typesense-server.exe --data-dir=C:\typesense-data --api-key=' . TYPESENSE_API_KEY . ' --listen-port=' . TYPESENSE_PORT . '</pre>');
}

// ---------------------------------------------------------------
// Collection schemas
// ---------------------------------------------------------------
$schemas = [
    TYPESENSE_PATIENTS_COLLECTION => [
        'name'                  => TYPESENSE_PATIENTS_COLLECTION,
        'fields'                => [
            ['name' => 'id',           'type' => 'string'],
            ['name' => 'numeric_id',   'type' => 'int32'],
            ['name' => 'patient_code', 'type' => 'string', 'optional' => true],
            ['name' => 'full_name',    'type' => 'string'],
            ['name' => 'phone',        'type' => 'string', 'optional' => true],
            ['name' => 'phone2',       'type' => 'string', 'optional' => true],
        ],
        'default_sorting_field' => 'numeric_id',
    ],
    TYPESENSE_VISITS_COLLECTION => [
        'name'                  => TYPESENSE_VISITS_COLLECTION,
        'fields'                => [
            ['name' => 'id',                  'type' => 'string'],
            ['name' => 'numeric_id',          'type' => 'int32'],
            ['name' => 'patient_name',        'type' => 'string'],
            ['name' => 'mobile_number',       'type' => 'string', 'optional' => true],
            ['name' => 'visit_date_ts',       'type' => 'int64'],
            ['name' => 'patient_status',      'type' => 'string', 'optional' => true],
            ['name' => 'assigned_doctor_id',  'type' => 'int32',  'optional' => true],
            ['name' => 'requested_doctor_id', 'type' => 'int32',  'optional' => true],
        ],
        'default_sorting_field' => 'visit_date_ts',
    ],
];

$log = [];

foreach ($schemas as $name => $schema) {
    if ($ts->collectionExists($name)) {
        $ts->deleteCollection($name);
        $log[] = "🗑  Deleted existing collection: <b>$name</b>";
    }
    $r = $ts->createCollection($schema);
    if ($r['code'] === 201) {
        $log[] = "✅ Created collection: <b>$name</b>";
    } else {
        $log[] = "❌ Failed to create <b>$name</b>: " . htmlspecialchars($r['data']['message'] ?? json_encode($r['data']));
    }
}

echo '<link rel="stylesheet" href="' . APP_URL . '/assets/css/fonts.css">';
echo '<style>body{font-family:var(--app-font-family);padding:20px;} p{margin:4px 0;}</style>';
echo '<h3>Typesense Setup</h3>';
foreach ($log as $line) {
    echo "<p>$line</p>";
}
echo '<p><br>✔ Done. <a href="sync.php">Run sync.php</a> to index existing data.</p>';
