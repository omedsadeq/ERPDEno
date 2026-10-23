<?php
// CLI-only test bootstrap. Builds a scratch DB (denthisab_test) and boots the
// app config against it, then applies the same migrations the live app uses
// (into tests/storage/migrations.lock — NEVER the app's real storage/).
if (PHP_SAPI !== 'cli') { die('tests are CLI only'); }

define('APP_TESTING', true);

putenv('DB_NAME=denthisab_test');
$_ENV['DB_NAME'] = 'denthisab_test';

// ── Resolve host/user/pass exactly the way config/config.php does ─────────
// (copied from config/config.php:24-35 — $isLocal / $dbDefaults) so the PDO
// connection we open here to create the scratch DB uses the same credentials
// config.php will use a few lines below when it connects to DB_NAME=denthisab_test.
$serverHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$isLocal = DIRECTORY_SEPARATOR === '\\'
    || strpos($serverHost, 'localhost') === 0
    || strpos($serverHost, '127.0.0.1') === 0
    || strpos($serverHost, '::1') === 0
    || substr($serverHost, -6) === '.local'
    || substr($serverHost, -5) === '.test';

// Kept in step with config/config.php:33-35. This copy had drifted (it still
// carried a '12345' root password and the old hosting credentials), which made
// every test run die at the PDO connect below with "Access denied".
$dbDefaults = $isLocal
    ? ['host' => 'localhost', 'name' => 'leza', 'user' => 'root', 'pass' => '']
    : ['host' => 'localhost', 'name' => 'leza', 'user' => 'root', 'pass' => ''];

$host = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: $dbDefaults['host'];
$user = $_ENV['DB_USER'] ?? getenv('DB_USER') ?: $dbDefaults['user'];
$pass = $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?: $dbDefaults['pass'];

// ── Safety net: guarantee the REAL app's storage/ files are byte-identical
// after this run, no matter what happens below. Database::schemaCachePath()
// / schemaCacheStamp() resolve to APP_ROOT/storage/... via a hardcoded
// dirname(__DIR__) — NOT configurable — so a stray tableExists() cache
// rebuild during a test run (test DB is connected at that point) could
// otherwise write test-DB table names into the live app's schema cache.
// Snapshot the two files that code path can touch and restore them exactly
// on shutdown, whatever happened during the run.
$realStorageDir = dirname(__DIR__) . '/storage';
$guardedFiles = [
    $realStorageDir . '/migrations.lock',
    $realStorageDir . '/schema_tables.cache',
];
$guardSnapshot = [];
foreach ($guardedFiles as $gf) {
    $guardSnapshot[$gf] = file_exists($gf) ? ['data' => file_get_contents($gf), 'mtime' => filemtime($gf)] : null;
}
register_shutdown_function(function () use ($guardSnapshot) {
    foreach ($guardSnapshot as $gf => $orig) {
        if ($orig === null) {
            if (file_exists($gf)) { @unlink($gf); }
            continue;
        }
        if (!file_exists($gf) || file_get_contents($gf) !== $orig['data']) {
            @file_put_contents($gf, $orig['data'], LOCK_EX);
        }
        @touch($gf, $orig['mtime']);
    }
});

// Database::tableExists() reads storage/schema_tables.cache, valid as long as
// its stored stamp matches storage/migrations.lock's mtime. Because the real
// migrations.lock is deliberately left untouched (see the shutdown restore
// above), that stamp always matches during a test run — so a cache warmed by
// the real dev DB (which currently has e.g. "doctors":true / "visits":true,
// see the Concerns note further down) would be a guaranteed HIT while this
// process is connected to denthisab_test, silently answering tableExists()
// for the WRONG database. Delete the real cache now (already snapshotted
// above) so the first tableExists() call in this run is forced through a
// genuine SHOW TABLES against whatever is actually connected; the shutdown
// restore puts the original file back regardless of what gets written here.
@unlink($realStorageDir . '/schema_tables.cache');

// ── Create the scratch DB before config.php connects to it ────────────────
$pdo = new PDO('mysql:host=' . $host . ';port=3306;charset=utf8mb4', $user, $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$fresh = getenv('TEST_KEEP_DB') ? false : true;
if ($fresh) { $pdo->exec('DROP DATABASE IF EXISTS denthisab_test'); }
$pdo->exec('CREATE DATABASE IF NOT EXISTS denthisab_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo->exec('USE denthisab_test');
if ($fresh) {
    // Base schema. database/schema.sql opens with its own
    // `CREATE DATABASE IF NOT EXISTS dental_clinics_soran ... USE dental_clinics_soran;`
    // — those two statements must be skipped or they would silently switch
    // this connection off denthisab_test and onto (creating) a stray
    // dental_clinics_soran database, leaving the scratch DB empty.
    $schema = file_get_contents(__DIR__ . '/../database/schema.sql');
    $schema = preg_replace('/^--.*$/m', '', $schema);
    foreach (array_filter(array_map('trim', explode(';', $schema))) as $stmt) {
        if (preg_match('/^\s*(CREATE\s+DATABASE|USE)\s+/i', $stmt)) { continue; }
        try { $pdo->exec($stmt); } catch (PDOException $e) { /* seed rows may collide; tables must not */ }
    }
}
unset($pdo);

// ── Boot the app config against denthisab_test ─────────────────────────────
// Pre-defining MIGRATIONS_RUNNING makes config.php skip its own auto-migration
// block (config/config.php ~line 372), which otherwise points a MigrationRunner
// at the REAL storage/migrations.lock regardless of which DB is connected.
// We run our own MigrationRunner below, against tests/storage/, instead.
define('MIGRATIONS_RUNNING', true);
require_once __DIR__ . '/../config/config.php';   // connects Database:: to denthisab_test

// config.php always calls session_start() when no session is active yet; on
// some CLI setups the session can end up not fully ACTIVE, so make sure
// $_SESSION exists as an array for classes that read $_SESSION['user_id'].
if (session_status() !== PHP_SESSION_ACTIVE) { $_SESSION = $_SESSION ?? []; }

require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/MigrationRunner.php';
$runner = new MigrationRunner(
    Database::getInstance()->getConnection(),
    __DIR__ . '/../database/migrations',
    __DIR__ . '/storage'                    // test-local lock dir — NEVER storage/migrations.lock
);
@mkdir(__DIR__ . '/storage');

// MigrationRunner sorts *.sql files alphabetically, but a chunk of the older
// (undated) migration filenames sort AFTER the dated 2026_MM_DD_*.sql files
// that ALTER the tables those older files CREATE — e.g. 2026_05_13_xray_
// request_workflow_tracking.sql ALTERs xray_requests, which add_xray_module.sql
// (alphabetically later) creates. On the live app this self-heals because new
// files only ever get added incrementally on top of an already-migrated DB; a
// brand-new scratch DB hits every ordering gap in one pass. MigrationRunner is
// documented as "safe to call on every request", so re-running it a few times
// lets each pass pick up what the previous pass unblocked, converging to the
// same end state the live app reaches over many requests. Errors from any
// still-unresolved statement are expected noise here (see below) and are
// redirected to a file instead of the real error_log/stderr.
$prevErrorLog = ini_get('error_log');
ini_set('error_log', __DIR__ . '/storage/migration-errors.log');
@unlink(__DIR__ . '/storage/migration-errors.log');
$prevApplied = -1;
for ($pass = 0; $pass < 5; $pass++) {
    @unlink(__DIR__ . '/storage/migrations.lock'); // force the slow path so retries are actually re-attempted
    $runner->run();
    $applied = (int) Database::getInstance()->fetch('SELECT COUNT(*) AS c FROM schema_migrations')['c'];
    if ($applied === $prevApplied) { break; } // converged — no point retrying further
    $prevApplied = $applied;
}
ini_set('error_log', $prevErrorLog);

// A few migrations end with `DEFAULT CHARSET=utf8mb4` and no COLLATE (e.g.
// create_work_types_table.sql, create_xray_pricing_table.sql). That does NOT
// inherit the database default — MariaDB applies the *server* default for the
// charset, utf8mb4_general_ci — so those tables land on a different collation
// from every other table here. Any later JOIN/LIKE mixing the two then dies
// with "Illegal mix of collations", which is a pure artefact of this scratch
// DB: the live database has long since been normalised, all 150 tables on
// utf8mb4_unicode_ci. Align the strays so tests exercise production's shape.
$strays = Database::getInstance()->fetchAll(
    "SELECT table_name FROM information_schema.tables
      WHERE table_schema = DATABASE()
        AND table_type = 'BASE TABLE'
        AND table_collation <> 'utf8mb4_unicode_ci'"
);
$collationFailures = [];
foreach ($strays as $stray) {
    $name = $stray['table_name'] ?? $stray['TABLE_NAME'] ?? null;
    if (!$name || !preg_match('/^[A-Za-z0-9_]+$/', $name)) { continue; }
    try {
        Database::getInstance()->getConnection()->exec(
            "ALTER TABLE `{$name}` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );
    } catch (Exception $e) {
        $collationFailures[] = $name . ': ' . $e->getMessage();
    }
}
// Never swallow these: an unconverted table surfaces much later as a baffling
// "Illegal mix of collations" inside whichever query happens to touch it.
if ($collationFailures) {
    fwrite(STDERR, "NOTE: could not normalise collation on " . count($collationFailures)
        . " table(s):\n  - " . implode("\n  - ", $collationFailures) . "\n");
}

// A handful of migrations ALTER `doctors` / `visits`, but no schema.sql or
// database/migrations/*.sql anywhere CREATEs either table — they exist on the
// live database only from undocumented manual DDL. Those migrations can never
// apply against a from-scratch database, in any order or number of passes.
// Surface that once, concisely, instead of staying silent about missing coverage.
$totalMigrationFiles = count(glob(__DIR__ . '/../database/migrations/*.sql') ?: []);
if ($prevApplied < $totalMigrationFiles) {
    fwrite(STDERR, "NOTE: {$prevApplied}/{$totalMigrationFiles} migrations applied to denthisab_test — "
        . ($totalMigrationFiles - $prevApplied) . " reference `doctors`/`visits`, which no schema.sql or "
        . "migration creates (see tests/storage/migration-errors.log).\n");
}
