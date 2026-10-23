<?php
/**
 * BackupManager - Database backup engine
 * سیستەمی زیرەکی کلینیکی ددان
 *
 * Creates verified backups of the configured database (MySQL / MariaDB):
 *   - full / structure-only / data-only / selected-tables
 *   - tables, data, indexes, FKs, AUTO_INCREMENT, views, triggers,
 *     procedures, functions, events, charset + collation
 *   - engine: mysqldump when the binary + proc_open are available,
 *     otherwise a pure-PHP streaming dump (shared-hosting safe)
 *   - output: .sql / .sql.gz / .zip, written in streamed chunks
 *   - every backup gets a SHA-256 checksum + history row in backup_history
 *   - portable across server families: collation names only one family knows
 *     (utf8mb4_0900_* on MySQL 8, utf8mb4_uca1400_* on MariaDB 11.4+) are
 *     rewritten by SqlCompat as the file is written, so the dump also imports
 *     through phpMyAdmin / the mysql CLI on the other family
 *
 * Credentials always come from config.php constants (DB_HOST, DB_NAME, ...);
 * nothing is hard-coded here. All files live in storage/backups/ which is
 * blocked from the web by .htaccess — downloads go through
 * admin/backup-download.php only.
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/AuditLog.php';
require_once __DIR__ . '/SqlCompat.php';

class BackupManager {

    /** Flush a multi-row INSERT once it grows past this many bytes (max_allowed_packet safety). */
    const INSERT_BATCH_BYTES = 800000;

    /** Settings keys this module owns inside the `settings` table. */
    const SETTING_KEYS = [
        'backup_auto_enabled', 'backup_auto_frequency', 'backup_auto_time',
        'backup_auto_weekday', 'backup_auto_monthday', 'backup_auto_type',
        'backup_auto_compression', 'backup_retention_count', 'backup_retention_days',
        'backup_notify', 'backup_last_auto_run', 'backup_cron_token',
    ];

    private $db;
    private $progressToken = null;
    private $progressTablesTotal = 0;
    private $progressTablesDone = 0;

    public function __construct() {
        $this->db = Database::getInstance();
        self::ensureDirs();
    }

    // ── Paths / progress files ────────────────────────────────────────

    public static function backupDir() {
        return APP_ROOT . '/storage/backups';
    }

    /** Create the storage dirs and make sure the web server can never serve them. */
    public static function ensureDirs() {
        $dir = self::backupDir();
        foreach ([$dir, $dir . '/.progress', $dir . '/.tmp'] as $d) {
            if (!is_dir($d)) {
                mkdir($d, 0750, true);
            }
        }
        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess,
                "# Backups contain the full clinic database - never serve them directly.\n" .
                "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n" .
                "<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n");
        }
        if (!file_exists($dir . '/index.html')) {
            file_put_contents($dir . '/index.html', '');
        }
    }

    /** Resolve a progress token to its file; null when the token is malformed. */
    public static function progressFile($token) {
        if (!is_string($token) || !preg_match('/^[a-f0-9]{16,64}$/', $token)) {
            return null;
        }
        return self::backupDir() . '/.progress/' . $token . '.json';
    }

    public static function writeProgress($token, array $data) {
        $file = self::progressFile($token);
        if ($file === null) {
            return;
        }
        $data['updated_at'] = time();
        file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    public static function readProgress($token) {
        $file = self::progressFile($token);
        if ($file === null || !file_exists($file)) {
            return null;
        }
        $data = json_decode((string)file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    private function progress($stage, $percent, $message, array $extra = []) {
        if ($this->progressToken === null) {
            return;
        }
        self::writeProgress($this->progressToken, array_merge([
            'stage'   => $stage,
            'percent' => max(0, min(100, (int)round($percent))),
            'message' => $message,
            'done'    => false,
        ], $extra));
    }

    // ── Capability detection ──────────────────────────────────────────

    /** True when PHP is allowed to launch external processes (proc_open not disabled). */
    public static function isExecAvailable() {
        if (!function_exists('proc_open')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', strtolower((string)ini_get('disable_functions'))));
        return !in_array('proc_open', $disabled, true);
    }

    /**
     * Locate a usable mysqldump / mariadb-dump binary. Checks PATH first,
     * then the usual XAMPP / WAMP / Laragon / Linux locations.
     */
    public static function findMysqldump() {
        static $cached = false, $result = null;
        if ($cached) {
            return $result;
        }
        $cached = true;

        if (!self::isExecAvailable()) {
            return $result = null;
        }

        $isWin = stripos(PHP_OS_FAMILY, 'Windows') !== false;
        $names = $isWin ? ['mysqldump.exe', 'mariadb-dump.exe'] : ['mysqldump', 'mariadb-dump'];

        $candidates = [];
        foreach ($names as $n) {
            $candidates[] = $n; // rely on PATH
        }
        if ($isWin) {
            foreach (['C:/xampp/mysql/bin', 'C:/xampp82/mysql/bin', 'C:/wamp64/bin/mysql/*/bin',
                      'C:/wamp/bin/mysql/*/bin', 'C:/laragon/bin/mysql/*/bin',
                      'C:/Program Files/MySQL/*/bin', 'C:/Program Files/MariaDB*/bin'] as $pattern) {
                foreach ((glob($pattern, GLOB_ONLYDIR) ?: [strpos($pattern, '*') === false ? $pattern : null]) as $bin) {
                    if ($bin === null) continue;
                    foreach ($names as $n) {
                        $candidates[] = $bin . '/' . $n;
                    }
                }
            }
        } else {
            foreach (['/usr/bin', '/usr/local/bin', '/usr/local/mysql/bin', '/opt/lampp/bin', '/opt/mysql/bin'] as $bin) {
                foreach ($names as $n) {
                    $candidates[] = $bin . '/' . $n;
                }
            }
        }

        foreach ($candidates as $bin) {
            $out = self::runProcess([$bin, '--version'], 5);
            if ($out !== null && $out['exit'] === 0 && stripos($out['stdout'], 'Ver') !== false) {
                return $result = $bin;
            }
        }
        return $result = null;
    }

    /** True when this mysqldump build knows --column-statistics (MySQL 8 client). */
    private static function supportsOption($binary, $option) {
        $out = self::runProcess([$binary, '--help', '--verbose'], 10);
        return $out !== null && strpos($out['stdout'], $option) !== false;
    }

    /** Run a short process, return ['exit'=>int,'stdout'=>string,'stderr'=>string] or null. */
    private static function runProcess(array $cmd, $timeoutSec = 10, array $extraEnv = []) {
        if (!self::isExecAvailable()) {
            return null;
        }
        $spec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $spec, $pipes, null, self::processEnv($extraEnv));
        if (!is_resource($proc)) {
            return null;
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = $stderr = '';
        $start = time();
        while (true) {
            $stdout .= (string)stream_get_contents($pipes[1]);
            $stderr .= (string)stream_get_contents($pipes[2]);
            $status = proc_get_status($proc);
            if (!$status['running']) {
                break;
            }
            if (time() - $start > $timeoutSec) {
                proc_terminate($proc);
                break;
            }
            usleep(50000);
        }
        $stdout .= (string)stream_get_contents($pipes[1]);
        $stderr .= (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $exit = isset($status) && !$status['running'] ? $status['exitcode'] : $exit;
        return ['exit' => (int)$exit, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /** Full current environment plus overrides (Windows needs SystemRoot etc.). */
    private static function processEnv(array $extra = []) {
        $env = [];
        foreach ($_SERVER as $k => $v) {
            if (is_string($v) && getenv($k) !== false) {
                $env[$k] = $v;
            }
        }
        foreach (['PATH', 'SystemRoot', 'TEMP', 'TMP', 'WINDIR', 'HOME'] as $k) {
            $v = getenv($k);
            if ($v !== false) {
                $env[$k] = $v;
            }
        }
        return array_merge($env, $extra);
    }

    /** Split DB_HOST into host + port ("localhost:3307" and plain hosts both work). */
    public static function hostAndPort() {
        $host = DB_HOST;
        $port = 3306;
        if (strpos($host, ':') !== false && strpos($host, ':') === strrpos($host, ':')) {
            [$h, $p] = explode(':', $host, 2);
            if (ctype_digit($p)) {
                $host = $h;
                $port = (int)$p;
            }
        }
        return [$host, $port];
    }

    /** Human capability summary for the UI. */
    public function capabilities() {
        $binary = self::findMysqldump();
        $server = $this->db->fetch("SELECT VERSION() AS v, DATABASE() AS d, CURRENT_USER() AS u");
        $size   = $this->db->fetch(
            "SELECT COUNT(*) AS tables_count,
                    IFNULL(SUM(TABLE_ROWS), 0) AS total_rows,
                    IFNULL(SUM(DATA_LENGTH + INDEX_LENGTH), 0) AS data_size
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
        );
        return [
            'exec_available'   => self::isExecAvailable(),
            'mysqldump_path'   => $binary,
            'zip_available'    => class_exists('ZipArchive'),
            'gzip_available'   => function_exists('gzopen'),
            'server_version'   => $server['v'] ?? '',
            'db_name'          => DB_NAME,
            'php_version'      => PHP_VERSION,
            // Connection diagnostics — answers "which database am I really on?"
            // when local and host configs diverge (IS_LOCAL in config.php).
            'is_local'         => defined('IS_LOCAL') ? (bool)IS_LOCAL : null,
            'db_actual'        => $server['d'] ?? '',
            'db_host'          => DB_HOST,
            'db_current_user'  => $server['u'] ?? '',
            'env_override'     => isset($_ENV['DB_NAME']) || getenv('DB_NAME') !== false,
            'tables_count'     => (int)($size['tables_count'] ?? 0),
            'total_rows'       => (int)($size['total_rows'] ?? 0),
            'data_size'        => (int)($size['data_size'] ?? 0),
            'upload_max'       => ini_get('upload_max_filesize'),
            'post_max'         => ini_get('post_max_size'),
            'max_exec'         => ini_get('max_execution_time'),
        ];
    }

    // ── Settings ──────────────────────────────────────────────────────

    public function getSettings() {
        $defaults = [
            'backup_auto_enabled' => '0', 'backup_auto_frequency' => 'daily',
            'backup_auto_time' => '02:00', 'backup_auto_weekday' => '5',
            'backup_auto_monthday' => '1', 'backup_auto_type' => 'full',
            'backup_auto_compression' => 'gzip', 'backup_retention_count' => '10',
            'backup_retention_days' => '30', 'backup_notify' => '1',
            'backup_last_auto_run' => '', 'backup_cron_token' => '',
        ];
        $rows = $this->db->fetchAll(
            "SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'backup_%'"
        );
        foreach ($rows as $r) {
            $defaults[$r['setting_key']] = (string)$r['setting_value'];
        }
        if ($defaults['backup_cron_token'] === '') {
            $defaults['backup_cron_token'] = $this->saveSetting('backup_cron_token', bin2hex(random_bytes(32)));
        }
        return $defaults;
    }

    public function saveSetting($key, $value) {
        if (!in_array($key, self::SETTING_KEYS, true)) {
            throw new InvalidArgumentException('Unknown backup setting: ' . $key);
        }
        $existing = $this->db->fetch("SELECT id FROM settings WHERE setting_key = :k", ['k' => $key]);
        if ($existing) {
            $this->db->update('settings', ['setting_value' => (string)$value], 'setting_key = :k', ['k' => $key]);
        } else {
            $this->db->insert('settings', ['setting_key' => $key, 'setting_value' => (string)$value]);
        }
        return (string)$value;
    }

    // ── Table metadata ────────────────────────────────────────────────

    /** All tables + views of the configured DB with approximate size info. */
    public function listTables() {
        return $this->db->fetchAll(
            "SELECT TABLE_NAME AS name, TABLE_TYPE AS type, ENGINE AS engine,
                    IFNULL(TABLE_ROWS, 0) AS approx_rows,
                    IFNULL(DATA_LENGTH + INDEX_LENGTH, 0) AS size_bytes
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
             ORDER BY TABLE_NAME"
        );
    }

    private function baseTables() {
        $out = [];
        foreach ($this->listTables() as $t) {
            if ($t['type'] === 'BASE TABLE') {
                $out[] = $t;
            }
        }
        return $out;
    }

    private function viewNames() {
        $out = [];
        foreach ($this->listTables() as $t) {
            if ($t['type'] === 'VIEW') {
                $out[] = $t['name'];
            }
        }
        return $out;
    }

    // ── Backup creation ───────────────────────────────────────────────

    /**
     * Create a backup.
     *
     * $opts:
     *   type          full|structure|data|tables   (default full)
     *   tables        array of table names          (required for type=tables)
     *   compression   gzip|zip|none                 (default gzip)
     *   method        auto|mysqldump|php            (default auto)
     *   trigger       manual|scheduled|pre_restore  (default manual)
     *   user_id       acting user id or null
     *   progress      progress token from the client (optional)
     */
    public function create(array $opts = []) {
        $type        = in_array($opts['type'] ?? 'full', ['full', 'structure', 'data', 'tables'], true) ? ($opts['type'] ?? 'full') : 'full';
        $compression = in_array($opts['compression'] ?? 'gzip', ['gzip', 'zip', 'none'], true) ? ($opts['compression'] ?? 'gzip') : 'gzip';
        $trigger     = in_array($opts['trigger'] ?? 'manual', ['manual', 'scheduled', 'pre_restore'], true) ? ($opts['trigger'] ?? 'manual') : 'manual';
        $userId      = isset($opts['user_id']) ? (int)$opts['user_id'] : null;
        $this->progressToken = $opts['progress'] ?? null;

        if ($compression === 'zip' && !class_exists('ZipArchive')) {
            $compression = 'gzip'; // graceful fallback on hosts without ext-zip
        }

        // Whitelist requested tables against what really exists (kills injection).
        $allTables = $this->baseTables();
        $allNames  = array_column($allTables, 'name');
        $selected  = null;
        if ($type === 'tables') {
            $selected = array_values(array_intersect((array)($opts['tables'] ?? []), $allNames));
            if (empty($selected)) {
                return ['success' => false, 'message' => 'هیچ خشتەیەکی دروست هەڵنەبژێردراوە'];
            }
        }

        // Pick the engine.
        $requestedMethod = $opts['method'] ?? 'auto';
        $binary = self::findMysqldump();
        if ($requestedMethod === 'mysqldump' && $binary === null) {
            return ['success' => false, 'message' => 'mysqldump لەم سێرڤەرە بەردەست نییە'];
        }
        $method = $requestedMethod === 'auto'
            ? ($binary !== null ? 'mysqldump' : 'php')
            : $requestedMethod;

        $ext      = $compression === 'gzip' ? '.sql.gz' : ($compression === 'zip' ? '.zip' : '.sql');
        $fileName = sprintf('%s_%s_%s_%s%s', DB_NAME, $type, date('Y-m-d_His'), substr(bin2hex(random_bytes(4)), 0, 8), $ext);
        $filePath = self::backupDir() . '/' . $fileName;

        $historyId = $this->db->insert('backup_history', [
            'file_name'       => $fileName,
            'backup_type'     => $type,
            'compression'     => $compression,
            'method'          => $method,
            'tables_included' => $selected ? implode(',', $selected) : null,
            'status'          => 'running',
            'trigger_source'  => $trigger,
            'db_name'         => DB_NAME,
            'created_by'      => $userId,
            'started_at'      => date('Y-m-d H:i:s'),
        ]);

        $this->progress('starting', 1, 'دەستکردن بە باکئەپ...');
        $writer = new BackupWriter($filePath, $compression);

        try {
            $dumpTables = $selected !== null
                ? array_values(array_filter($allTables, fn($t) => in_array($t['name'], $selected, true)))
                : $allTables;
            $this->progressTablesTotal = count($dumpTables);
            $this->progressTablesDone  = 0;

            if ($method === 'mysqldump') {
                try {
                    $this->dumpWithMysqldump($binary, $type, $selected, $writer, $dumpTables);
                } catch (Throwable $dumpError) {
                    if ($requestedMethod !== 'auto') {
                        throw $dumpError;
                    }
                    // auto mode: a broken/incompatible mysqldump (old client,
                    // auth plugin mismatch, missing privilege) falls back to
                    // the pure PHP engine instead of failing the backup.
                    error_log('[BackupManager] mysqldump failed, falling back to PHP engine: ' . $dumpError->getMessage());
                    $writer->abort();
                    $writer = new BackupWriter($filePath, $compression);
                    $method = 'php';
                    $this->db->update('backup_history', ['method' => 'php'], 'id = :id', ['id' => $historyId]);
                    $this->progress('fallback', 2, 'mysqldump شکستی هێنا — بزوێنەری PHP بەکاردێت...');
                    $this->dumpWithPhp($type, $writer, $dumpTables);
                }
            } else {
                $this->dumpWithPhp($type, $writer, $dumpTables);
            }

            $writer->close();
            clearstatcache(true, $filePath);
            $size = (int)filesize($filePath);
            if ($size <= 0) {
                throw new RuntimeException('فایلی باکئەپ بەتاڵە');
            }

            $this->progress('checksum', 97, 'هەژمارکردنی SHA-256...');
            $checksum = hash_file('sha256', $filePath);

            $this->db->update('backup_history', [
                'file_size'       => $size,
                'checksum_sha256' => $checksum,
                'status'          => 'completed',
                'completed_at'    => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => $historyId]);

            AuditLog::log('backup_create', 'backup_history', $historyId, null, [
                'file' => $fileName, 'type' => $type, 'method' => $method,
                'size' => $size, 'trigger' => $trigger,
            ], $userId);

            $this->progress('done', 100, 'باکئەپ تەواو بوو', [
                'done' => true, 'success' => true, 'id' => $historyId,
                'file_name' => $fileName, 'size' => $size, 'checksum' => $checksum,
            ]);

            return [
                'success' => true, 'id' => $historyId, 'file_name' => $fileName,
                'size' => $size, 'checksum' => $checksum, 'method' => $method,
            ];
        } catch (Throwable $e) {
            $writer->abort();
            $this->db->update('backup_history', [
                'status'        => 'failed',
                'error_message' => mb_substr($e->getMessage(), 0, 5000),
                'completed_at'  => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => $historyId]);
            AuditLog::log('backup_failed', 'backup_history', $historyId, null, ['error' => mb_substr($e->getMessage(), 0, 500)], $userId);
            $this->progress('error', 100, $e->getMessage(), ['done' => true, 'success' => false, 'error' => $e->getMessage()]);
            return ['success' => false, 'id' => $historyId, 'message' => $e->getMessage()];
        }
    }

    // ── Engine 1: mysqldump ───────────────────────────────────────────

    private function dumpWithMysqldump($binary, $type, $selected, BackupWriter $writer, array $dumpTables) {
        [$host, $port] = self::hostAndPort();

        $cmd = [
            $binary,
            '--host=' . $host,
            '--port=' . $port,
            '--user=' . DB_USER,
            '--default-character-set=' . (DB_CHARSET ?: 'utf8mb4'),
            '--single-transaction',
            '--quick',
            '--skip-lock-tables',
            '--hex-blob',
            '--add-drop-table',
            '--comments',
        ];
        if (self::supportsOption($binary, '--no-tablespaces')) {
            $cmd[] = '--no-tablespaces'; // MySQL 8 shared hosting: avoids PROCESS privilege errors
        }
        if (self::supportsOption($binary, '--column-statistics')) {
            $cmd[] = '--column-statistics=0'; // MySQL 8 client against MariaDB / MySQL 5.7 servers
        }

        if ($type === 'structure') {
            $cmd[] = '--no-data';
        } elseif ($type === 'data') {
            $cmd[] = '--no-create-info';
            $cmd[] = '--skip-triggers';
        }
        if ($type === 'full' || $type === 'structure') {
            $cmd[] = '--routines';
            $cmd[] = '--events';
            $cmd[] = '--triggers';
        }

        $cmd[] = DB_NAME;
        if ($selected !== null) {
            foreach ($selected as $t) {
                $cmd[] = $t;
            }
        }

        // Rough progress target: raw data size of the tables being dumped.
        $estimated = max(1, array_sum(array_map(fn($t) => (int)$t['size_bytes'], $dumpTables)));

        $spec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        // Password goes through the environment, never through the command line.
        $proc = proc_open($cmd, $spec, $pipes, null, self::processEnv(['MYSQL_PWD' => DB_PASS]));
        if (!is_resource($proc)) {
            throw new RuntimeException('نەتوانرا mysqldump دەستپێبکرێت');
        }

        stream_set_blocking($pipes[2], false);
        $stderr   = '';
        $rawBytes = 0;
        $lastTick = 0;

        while (!feof($pipes[1])) {
            $chunk = fread($pipes[1], 65536);
            if ($chunk === false) {
                break;
            }
            if ($chunk !== '') {
                $writer->write($chunk);
                $rawBytes += strlen($chunk);
            }
            $stderr .= (string)fread($pipes[2], 8192); // keep the stderr pipe drained
            if (time() !== $lastTick) {
                $lastTick = time();
                $pct = min(95, 3 + (int)($rawBytes / $estimated * 90));
                $this->progress('dumping', $pct, 'داگرتنی داتا لە داتابەیس... (' . self::humanSize($rawBytes) . ')');
            }
        }
        $stderr .= (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        // "Warning: ..." lines on stderr with exit 0 are fine (e.g. no EVENT privilege).
        if ($exit !== 0) {
            $stderr = trim(preg_replace('/mysqldump:\s*\[Warning\][^\n]*/i', '', $stderr));
            throw new RuntimeException('mysqldump شکستی هێنا (exit ' . $exit . '): ' . mb_substr($stderr ?: 'unknown error', 0, 1000));
        }
        if ($rawBytes === 0) {
            throw new RuntimeException('mysqldump هیچ داتایەکی نەنووسی: ' . mb_substr(trim($stderr), 0, 500));
        }
    }

    // ── Engine 2: pure PHP fallback ───────────────────────────────────

    private function dumpWithPhp($type, BackupWriter $writer, array $dumpTables) {
        $pdo = $this->db->getConnection();
        // Dedicated unbuffered connection: rows stream one by one, RAM stays flat.
        $streamPdo = $this->newStreamConnection();

        $withStructure = ($type !== 'data');
        $withData      = ($type !== 'structure');
        $withObjects   = ($type === 'full' || $type === 'structure'); // views/routines/events

        $writer->write($this->dumpHeader($type));

        $done  = 0;
        $total = max(1, count($dumpTables));
        foreach ($dumpTables as $t) {
            $table = $t['name'];
            $this->progress('dumping', 3 + (int)($done / $total * 85), 'خشتە: ' . $table . ' (' . ($done + 1) . '/' . $total . ')', ['current_table' => $table]);

            if ($withStructure) {
                $this->writeTableStructure($pdo, $writer, $table);
            }
            if ($withData) {
                $this->writeTableData($pdo, $streamPdo, $writer, $table, (int)$t['approx_rows']);
            }
            $done++;
        }

        if ($withObjects) {
            $this->progress('objects', 90, 'ڤیو، تریگەر و ڕووتینەکان...');
            $this->writeViews($pdo, $writer);
            $this->writeTriggers($pdo, $writer, array_column($dumpTables, 'name'));
            $this->writeRoutines($pdo, $writer);
            $this->writeEvents($pdo, $writer);
        } elseif ($withStructure) {
            // selected-tables backup: keep the triggers that belong to those tables
            $this->writeTriggers($pdo, $writer, array_column($dumpTables, 'name'));
        }

        $writer->write($this->dumpFooter());
        $streamPdo = null;
    }

    /** Second connection with unbuffered queries; TIMESTAMPs export as UTC like mysqldump. */
    private function newStreamConnection() {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE                  => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE       => PDO::FETCH_NUM,
            PDO::ATTR_EMULATE_PREPARES         => false,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
            PDO::MYSQL_ATTR_INIT_COMMAND       => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ]);
        $pdo->exec("SET time_zone = '+00:00', net_write_timeout = 3600");
        return $pdo;
    }

    private function dumpHeader($type) {
        $server = $this->db->fetch("SELECT VERSION() AS v");
        return "-- dentHisab Database Backup (" . APP_NAME_EN . ")\n"
            . "-- Host: " . DB_HOST . "    Database: " . DB_NAME . "\n"
            . "-- Server version: " . ($server['v'] ?? 'unknown') . "\n"
            . "-- Backup type: " . $type . "    Engine: PHP fallback\n"
            . "-- Generated: " . date('Y-m-d H:i:s') . " (" . TIMEZONE . ")\n"
            . "-- ------------------------------------------------------\n\n"
            . "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n"
            . "/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;\n"
            . "/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;\n"
            . "/*!40101 SET NAMES utf8mb4 */;\n"
            . "/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;\n"
            . "/*!40103 SET TIME_ZONE='+00:00' */;\n"
            . "/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;\n"
            . "/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;\n"
            . "/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n"
            . "/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;\n\n";
    }

    private function dumpFooter() {
        return "\n/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;\n"
            . "/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;\n"
            . "/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;\n"
            . "/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;\n"
            . "/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n"
            . "/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;\n"
            . "/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;\n"
            . "/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;\n\n"
            . "-- Dump completed on " . date('Y-m-d H:i:s') . "\n";
    }

    private function writeTableStructure(PDO $pdo, BackupWriter $writer, $table) {
        $row = $pdo->query("SHOW CREATE TABLE `" . str_replace('`', '``', $table) . "`")->fetch(PDO::FETCH_ASSOC);
        $create = $row['Create Table'] ?? array_values($row)[1] ?? null;
        if ($create === null) {
            throw new RuntimeException("SHOW CREATE TABLE failed for {$table}");
        }
        $writer->write("--\n-- Table structure for table `{$table}`\n--\n\n");
        $writer->write("DROP TABLE IF EXISTS `{$table}`;\n");
        $writer->write($create . ";\n\n");
    }

    private function writeTableData(PDO $pdo, PDO $streamPdo, BackupWriter $writer, $table, $approxRows) {
        // Explicit column list: generated columns must not be dumped/re-inserted.
        $columns = $pdo->query("SHOW FULL COLUMNS FROM `" . str_replace('`', '``', $table) . "`")->fetchAll(PDO::FETCH_ASSOC);
        $names = $binary = [];
        foreach ($columns as $c) {
            if (stripos((string)$c['Extra'], 'GENERATED') !== false) {
                continue;
            }
            $names[]  = $c['Field'];
            $binary[] = (bool)preg_match('/binary|blob|bit\(|geometry|point|linestring|polygon/i', (string)$c['Type']);
        }
        if (empty($names)) {
            return;
        }

        $colList = '`' . implode('`, `', array_map(fn($n) => str_replace('`', '``', $n), $names)) . '`';
        $stmt = $streamPdo->query("SELECT {$colList} FROM `" . str_replace('`', '``', $table) . "`");

        $writer->write("--\n-- Dumping data for table `{$table}`\n--\n\n");
        $writer->write("/*!40000 ALTER TABLE `{$table}` DISABLE KEYS */;\n");

        $insertHead = "INSERT INTO `{$table}` ({$colList}) VALUES\n";
        $batch      = '';
        $rows       = 0;
        $lastTick   = 0;

        while (($row = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
            $vals = [];
            foreach ($row as $i => $v) {
                $vals[] = $this->sqlValue($v, $binary[$i]);
            }
            $tuple = '(' . implode(',', $vals) . ')';

            if ($batch === '') {
                $batch = $insertHead . $tuple;
            } else {
                $batch .= ",\n" . $tuple;
            }
            $rows++;

            if (strlen($batch) >= self::INSERT_BATCH_BYTES) {
                $writer->write($batch . ";\n");
                $batch = '';
            }
            if ($rows % 2000 === 0 && time() !== $lastTick) {
                $lastTick = time();
                $this->progress('dumping',
                    3 + (int)($this->progressTablesDone / max(1, $this->progressTablesTotal) * 85),
                    "خشتە: {$table} — " . number_format($rows) . " ڕیز" . ($approxRows > 0 ? ' / ~' . number_format($approxRows) : ''));
            }
        }
        if ($batch !== '') {
            $writer->write($batch . ";\n");
        }
        $writer->write("/*!40000 ALTER TABLE `{$table}` ENABLE KEYS */;\n\n");
        $stmt->closeCursor();
        $this->progressTablesDone++;
    }

    /** One value rendered as SQL. Binary columns become hex so utf8mb4/emoji survive untouched. */
    private function sqlValue($v, $isBinary) {
        if ($v === null) {
            return 'NULL';
        }
        if (is_int($v)) {
            return (string)$v;
        }
        if (is_float($v)) {
            return sprintf('%.17G', $v);
        }
        $v = (string)$v;
        if ($isBinary) {
            return $v === '' ? "''" : '0x' . bin2hex($v);
        }
        return "'" . strtr($v, [
            "\\"   => "\\\\",
            "'"    => "\\'",
            "\0"   => "\\0",
            "\n"   => "\\n",
            "\r"   => "\\r",
            "\x1a" => "\\Z",
        ]) . "'";
    }

    private function writeViews(PDO $pdo, BackupWriter $writer) {
        $views = $this->viewNames();
        if (empty($views)) {
            return;
        }
        // Order views so that a view referencing another view is created after it.
        $defs = [];
        foreach ($views as $v) {
            $row = $pdo->query("SHOW CREATE VIEW `" . str_replace('`', '``', $v) . "`")->fetch(PDO::FETCH_ASSOC);
            $defs[$v] = (string)($row['Create View'] ?? '');
        }
        $ordered = [];
        $remaining = $views;
        for ($pass = 0; $pass < count($views) && !empty($remaining); $pass++) {
            foreach ($remaining as $k => $v) {
                $dependsOnRemaining = false;
                foreach ($remaining as $other) {
                    if ($other !== $v && strpos($defs[$v], "`{$other}`") !== false) {
                        $dependsOnRemaining = true;
                        break;
                    }
                }
                if (!$dependsOnRemaining) {
                    $ordered[] = $v;
                    unset($remaining[$k]);
                }
            }
        }
        $ordered = array_merge($ordered, array_values($remaining)); // cycles: dump anyway

        foreach ($ordered as $v) {
            if ($defs[$v] === '') {
                continue;
            }
            $writer->write("--\n-- View `{$v}`\n--\n\n");
            $writer->write("DROP VIEW IF EXISTS `{$v}`;\n");
            $writer->write($this->stripDefiner($defs[$v]) . ";\n\n");
        }
    }

    private function writeTriggers(PDO $pdo, BackupWriter $writer, array $tableNames) {
        $triggers = $pdo->query("SHOW TRIGGERS")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($triggers as $tr) {
            if (!in_array($tr['Table'], $tableNames, true)) {
                continue;
            }
            $row = $pdo->query("SHOW CREATE TRIGGER `" . str_replace('`', '``', $tr['Trigger']) . "`")->fetch(PDO::FETCH_ASSOC);
            $create = $row['SQL Original Statement'] ?? null;
            if (!$create) {
                continue;
            }
            $writer->write("--\n-- Trigger `{$tr['Trigger']}`\n--\n\n");
            $writer->write("DROP TRIGGER IF EXISTS `{$tr['Trigger']}`;\n");
            $writer->write("DELIMITER ;;\n" . $this->stripDefiner($create) . " ;;\nDELIMITER ;\n\n");
        }
    }

    private function writeRoutines(PDO $pdo, BackupWriter $writer) {
        foreach (['PROCEDURE', 'FUNCTION'] as $kind) {
            try {
                $stmt = $pdo->prepare("SHOW {$kind} STATUS WHERE Db = :db");
                $stmt->execute(['db' => DB_NAME]);
                $routines = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                continue; // no privilege — skip silently like mysqldump does
            }
            foreach ($routines as $r) {
                try {
                    $row = $pdo->query("SHOW CREATE {$kind} `" . str_replace('`', '``', $r['Name']) . "`")->fetch(PDO::FETCH_ASSOC);
                } catch (Exception $e) {
                    continue;
                }
                $key = $kind === 'PROCEDURE' ? 'Create Procedure' : 'Create Function';
                $create = $row[$key] ?? null;
                if (!$create) {
                    $writer->write("-- Skipped {$kind} `{$r['Name']}` (no SHOW CREATE privilege)\n\n");
                    continue;
                }
                $writer->write("--\n-- {$kind} `{$r['Name']}`\n--\n\n");
                $writer->write("DROP {$kind} IF EXISTS `{$r['Name']}`;\n");
                $writer->write("DELIMITER ;;\n" . $this->stripDefiner($create) . " ;;\nDELIMITER ;\n\n");
            }
        }
    }

    private function writeEvents(PDO $pdo, BackupWriter $writer) {
        try {
            $events = $pdo->query("SHOW EVENTS FROM `" . str_replace('`', '``', DB_NAME) . "`")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return; // EVENT privilege missing — fine
        }
        foreach ($events as $ev) {
            try {
                $row = $pdo->query("SHOW CREATE EVENT `" . str_replace('`', '``', $ev['Name']) . "`")->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                continue;
            }
            $create = $row['Create Event'] ?? null;
            if (!$create) {
                continue;
            }
            $writer->write("--\n-- Event `{$ev['Name']}`\n--\n\n");
            $writer->write("DROP EVENT IF EXISTS `{$ev['Name']}`;\n");
            $writer->write("DELIMITER ;;\n" . $this->stripDefiner($create) . " ;;\nDELIMITER ;\n\n");
        }
    }

    /** DEFINER clauses break restores on hosts with a different DB user — drop them. */
    private function stripDefiner($sql) {
        return preg_replace('/\sDEFINER=`[^`]*`@`[^`]*`/i', ' ', $sql);
    }

    // ── Verify / delete / retention / uploads ─────────────────────────

    public function getById($id) {
        return $this->db->fetch("SELECT * FROM backup_history WHERE id = :id", ['id' => (int)$id]);
    }

    public function getHistory($limit = 200) {
        return $this->db->fetchAll(
            "SELECT bh.*, u.full_name AS created_by_name
             FROM backup_history bh
             LEFT JOIN users u ON u.id = bh.created_by
             ORDER BY bh.id DESC
             LIMIT " . max(1, (int)$limit)
        );
    }

    /** Absolute path of a history row's file — guaranteed inside the backup dir. */
    public function filePathFor(array $row) {
        $name = basename((string)($row['file_name'] ?? ''));
        if ($name === '' || $name[0] === '.') {
            return null;
        }
        $path = self::backupDir() . '/' . $name;
        $real = realpath($path);
        $dir  = realpath(self::backupDir());
        if ($real === false || $dir === false || strpos($real, $dir) !== 0) {
            return null;
        }
        return $real;
    }

    /**
     * Verify a backup: file present, size + SHA-256 match the history row,
     * archive readable end-to-end, dump footer marker present.
     */
    public function verify($id) {
        $row = $this->getById($id);
        if (!$row) {
            return ['success' => false, 'message' => 'باکئەپ نەدۆزرایەوە'];
        }
        $result = [
            'success' => false, 'file_exists' => false, 'size_ok' => false,
            'checksum_ok' => false, 'readable' => false, 'marker_ok' => false,
        ];
        $path = $this->filePathFor($row);
        if ($path === null || !is_file($path)) {
            $result['message'] = 'فایلەکە لەسەر دیسک نەدۆزرایەوە';
            return $result;
        }
        $result['file_exists'] = true;
        clearstatcache(true, $path);
        $result['size_ok'] = ((int)filesize($path) === (int)$row['file_size']);

        if (!empty($row['checksum_sha256'])) {
            $result['checksum_ok'] = hash_equals($row['checksum_sha256'], (string)hash_file('sha256', $path));
        }

        // Stream through the whole archive: catches truncation + gzip CRC damage,
        // and lets us look for the end-of-dump marker.
        $tail = '';
        try {
            if (substr($path, -4) === '.zip') {
                $zip = new ZipArchive();
                if ($zip->open($path) === true) {
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $entry = $zip->getNameIndex($i);
                        if (substr($entry, -4) === '.sql') {
                            $fp = $zip->getStream($entry);
                            if ($fp) {
                                while (!feof($fp)) {
                                    $chunk = fread($fp, 262144);
                                    if ($chunk === false) break;
                                    $tail = substr($tail . $chunk, -1024);
                                }
                                fclose($fp);
                                $result['readable'] = true;
                            }
                            break;
                        }
                    }
                    $zip->close();
                }
            } else {
                $gz = gzopen($path, 'rb'); // reads .sql.gz and plain .sql alike
                if ($gz !== false) {
                    $readOk = true;
                    while (!gzeof($gz)) {
                        $chunk = gzread($gz, 262144);
                        if ($chunk === false) {
                            $readOk = false;
                            break;
                        }
                        $tail = substr($tail . $chunk, -1024);
                    }
                    gzclose($gz);
                    $result['readable'] = $readOk;
                }
            }
        } catch (Throwable $e) {
            $result['readable'] = false;
        }
        $result['marker_ok'] = (strpos($tail, 'Dump completed') !== false);

        $result['success'] = $result['file_exists'] && $result['size_ok']
            && $result['checksum_ok'] && $result['readable'];

        AuditLog::log('backup_verify', 'backup_history', (int)$id, null, [
            'ok' => $result['success'], 'marker' => $result['marker_ok'],
        ]);
        return $result;
    }

    public function delete($id, $userId = null) {
        $row = $this->getById($id);
        if (!$row) {
            return ['success' => false, 'message' => 'باکئەپ نەدۆزرایەوە'];
        }
        if ($row['status'] === 'running') {
            return ['success' => false, 'message' => 'ئەم باکئەپە هێشتا کاردەکات'];
        }
        $path = $this->filePathFor($row);
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
        $this->db->update('backup_history', ['status' => 'deleted'], 'id = :id', ['id' => (int)$id]);
        AuditLog::log('backup_delete', 'backup_history', (int)$id, ['file' => $row['file_name']], null, $userId);
        return ['success' => true];
    }

    /** Apply the configured retention (count + age). Uploads are never auto-deleted. */
    public function applyRetention() {
        $settings = $this->getSettings();
        $keepCount = max(0, (int)$settings['backup_retention_count']);
        $keepDays  = max(0, (int)$settings['backup_retention_days']);
        $deleted   = 0;

        $rows = $this->db->fetchAll(
            "SELECT id, file_name, started_at FROM backup_history
             WHERE status = 'completed' AND trigger_source != 'upload'
             ORDER BY id DESC"
        );
        foreach ($rows as $i => $row) {
            $tooMany = ($keepCount > 0 && $i >= $keepCount);
            $tooOld  = ($keepDays > 0 && strtotime($row['started_at']) < strtotime("-{$keepDays} days"));
            if ($tooMany || $tooOld) {
                $res = $this->delete((int)$row['id']);
                if (!empty($res['success'])) {
                    $deleted++;
                }
            }
        }
        if ($deleted > 0) {
            AuditLog::log('backup_retention', 'backup_history', null, null, ['deleted' => $deleted]);
        }
        return ['success' => true, 'deleted' => $deleted];
    }

    /**
     * Register an uploaded backup file (for restore). Validates extension and
     * magic bytes, stores it under a server-generated name, records history.
     */
    public function registerUpload(array $file, $userId = null) {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'بارکردنی فایل سەرکەوتوو نەبوو (کۆدی ' . ($file['error'] ?? '?') . ')'];
        }
        $orig = (string)$file['name'];
        $ext  = null;
        foreach (['.sql.gz', '.sql', '.zip'] as $e) {
            if (strtolower(substr($orig, -strlen($e))) === $e) {
                $ext = $e;
                break;
            }
        }
        if ($ext === null) {
            return ['success' => false, 'message' => 'تەنها فایلی .sql و .sql.gz و .zip ڕێگەپێدراوە'];
        }

        $head = (string)file_get_contents($file['tmp_name'], false, null, 0, 4096);
        if ($ext === '.sql.gz' && substr($head, 0, 2) !== "\x1f\x8b") {
            return ['success' => false, 'message' => 'فایلەکە gzip ی دروست نییە'];
        }
        if ($ext === '.zip' && substr($head, 0, 4) !== "PK\x03\x04") {
            return ['success' => false, 'message' => 'فایلەکە zip ی دروست نییە'];
        }
        if ($ext === '.sql' && strpos($head, "\0") !== false) {
            return ['success' => false, 'message' => 'فایلی .sql دەبێت دەقی سادە بێت'];
        }

        $fileName = sprintf('upload_%s_%s%s', date('Y-m-d_His'), substr(bin2hex(random_bytes(4)), 0, 8), $ext);
        $dest     = self::backupDir() . '/' . $fileName;
        $moved    = is_uploaded_file($file['tmp_name'])
            ? move_uploaded_file($file['tmp_name'], $dest)
            : rename($file['tmp_name'], $dest); // CLI/tests
        if (!$moved) {
            return ['success' => false, 'message' => 'نەتوانرا فایلەکە پاشەکەوت بکرێت'];
        }

        $id = $this->db->insert('backup_history', [
            'file_name'       => $fileName,
            'file_size'       => (int)filesize($dest),
            'backup_type'     => 'full',
            'compression'     => $ext === '.sql.gz' ? 'gzip' : ($ext === '.zip' ? 'zip' : 'none'),
            'method'          => null,
            'checksum_sha256' => hash_file('sha256', $dest),
            'status'          => 'completed',
            'trigger_source'  => 'upload',
            'db_name'         => DB_NAME,
            'created_by'      => $userId,
            'started_at'      => date('Y-m-d H:i:s'),
            'completed_at'    => date('Y-m-d H:i:s'),
        ]);
        AuditLog::log('backup_upload', 'backup_history', $id, null, ['file' => $fileName, 'original' => mb_substr($orig, 0, 200)], $userId);
        return ['success' => true, 'id' => $id, 'file_name' => $fileName];
    }

    /**
     * Re-insert history rows for backup files that exist on disk but are
     * missing from backup_history (happens after restoring an older dump).
     */
    public function reconcileFiles() {
        $known = array_column(
            $this->db->fetchAll("SELECT file_name FROM backup_history"),
            'file_name'
        );
        $added = 0;
        foreach ((glob(self::backupDir() . '/*.{sql,gz,zip}', GLOB_BRACE) ?: []) as $path) {
            $name = basename($path);
            if (in_array($name, $known, true)) {
                continue;
            }
            $ext = strtolower($name);
            $this->db->insert('backup_history', [
                'file_name'       => $name,
                'file_size'       => (int)filesize($path),
                'backup_type'     => 'full',
                'compression'     => substr($ext, -3) === '.gz' ? 'gzip' : (substr($ext, -4) === '.zip' ? 'zip' : 'none'),
                'checksum_sha256' => hash_file('sha256', $path),
                'status'          => 'completed',
                'trigger_source'  => 'manual',
                'db_name'         => DB_NAME,
                'started_at'      => date('Y-m-d H:i:s', filemtime($path) ?: time()),
                'completed_at'    => date('Y-m-d H:i:s', filemtime($path) ?: time()),
            ]);
            $added++;
        }
        return $added;
    }

    public function stats() {
        $row = $this->db->fetch(
            "SELECT COUNT(*) AS total, IFNULL(SUM(file_size), 0) AS total_size,
                    MAX(completed_at) AS last_backup
             FROM backup_history WHERE status = 'completed'"
        );
        return [
            'total'       => (int)($row['total'] ?? 0),
            'total_size'  => (int)($row['total_size'] ?? 0),
            'last_backup' => $row['last_backup'] ?? null,
        ];
    }

    public static function humanSize($bytes) {
        $bytes = (float)$bytes;
        foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $unit) {
            if ($bytes < 1024 || $unit === 'TB') {
                return round($bytes, $unit === 'B' ? 0 : 1) . ' ' . $unit;
            }
            $bytes /= 1024;
        }
        return '0 B';
    }
}

/**
 * BackupWriter - streamed output for BackupManager.
 * gzip writes compress on the fly; zip collects a temp .sql first because
 * the zip central directory can only be finalized at the end.
 */
class BackupWriter {

    private $compression;
    private $finalPath;
    private $tmpPath = null;
    private $handle;
    private $closed = false;
    private $normalizer;

    public function __construct($finalPath, $compression) {
        $this->finalPath   = $finalPath;
        $this->compression = $compression;
        // Every byte of both engines passes through here, so this is where the
        // dump is made portable: MySQL 8 / new-MariaDB collation names are
        // rewritten as the file is written, not only when we restore it.
        $this->normalizer  = new SqlCompatStream();

        if ($compression === 'gzip') {
            $this->handle = gzopen($finalPath, 'wb6');
        } elseif ($compression === 'zip') {
            $this->tmpPath = BackupManager::backupDir() . '/.tmp/' . basename($finalPath, '.zip') . '.sql';
            $this->handle  = fopen($this->tmpPath, 'wb');
        } else {
            $this->handle = fopen($finalPath, 'wb');
        }
        if ($this->handle === false) {
            throw new RuntimeException('نەتوانرا فایلی باکئەپ بکرێتەوە بۆ نووسین');
        }
    }

    public function write($data) {
        if ($data === '') {
            return;
        }
        // The normalizer buffers a partial line, so it can legitimately return
        // nothing here — the held-back bytes are emitted by the next write/close.
        $this->emit($this->normalizer->feed($data));
    }

    private function emit($data) {
        if ($data === '') {
            return;
        }
        $ok = $this->compression === 'gzip'
            ? gzwrite($this->handle, $data)
            : fwrite($this->handle, $data);
        if ($ok === false || $ok === 0) {
            throw new RuntimeException('نووسین بۆ فایلی باکئەپ شکستی هێنا (دیسک پڕە؟)');
        }
    }

    /** old => new collation names rewritten while writing, for the backup log. */
    public function compatFixes() {
        return $this->normalizer->appliedFixes();
    }

    public function close() {
        if ($this->closed) {
            return;
        }
        $this->emit($this->normalizer->flush()); // dumps need not end with a newline
        $this->closed = true;
        $this->compression === 'gzip' ? gzclose($this->handle) : fclose($this->handle);

        if ($this->compression === 'zip') {
            $zip = new ZipArchive();
            if ($zip->open($this->finalPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('نەتوانرا فایلی zip دروست بکرێت');
            }
            $zip->addFile($this->tmpPath, basename($this->finalPath, '.zip') . '.sql');
            if (!$zip->close()) {
                throw new RuntimeException('داخستنی فایلی zip شکستی هێنا');
            }
            @unlink($this->tmpPath);
        }
    }

    public function abort() {
        if (!$this->closed) {
            $this->closed = true;
            try {
                $this->compression === 'gzip' ? gzclose($this->handle) : fclose($this->handle);
            } catch (Throwable $e) {
                // handle already dead
            }
        }
        if ($this->tmpPath !== null && file_exists($this->tmpPath)) {
            @unlink($this->tmpPath);
        }
        if (file_exists($this->finalPath)) {
            @unlink($this->finalPath);
        }
    }
}
