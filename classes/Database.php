<?php
/**
 * Database Class - PDO Connection Handler
 * سیستەمی زیرەکی کلینیکی ددان
 */

class Database {
    private static $instance = null;
    private $connection;

    private function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            // PDO::MYSQL_ATTR_* is deprecated in PHP 8.5; Pdo\Mysql exists from 8.4.
            $initCommand = defined('Pdo\Mysql::ATTR_INIT_COMMAND')
                ? \Pdo\Mysql::ATTR_INIT_COMMAND
                : PDO::MYSQL_ATTR_INIT_COMMAND;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                $initCommand => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ];
            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
            // Align MySQL's session clock with PHP (Asia/Baghdad = UTC+3, no DST),
            // so CURDATE()/NOW() in queries agree with timestamps written via PHP
            // date() — otherwise "today" filters cover a different 24h window on
            // hosts whose MySQL server timezone differs (e.g. shared hosting).
            $this->connection->exec("SET time_zone = '+03:00'");
        } catch (PDOException $e) {
            if (defined('RECOVERY_MODE')) {
                throw $e; // recovery.php diagnoses connection problems itself
            }
            $errno = (int)($e->errorInfo[1] ?? 0);
            if ($errno === 1049 || stripos($e->getMessage(), 'Unknown database') !== false) {
                self::redirectToRecovery('missing_db');
            }
            if ($errno === 2002 || stripos($e->getMessage(), 'No connection could be made') !== false
                || stripos($e->getMessage(), 'Connection refused') !== false) {
                self::redirectToRecovery('server_down');
            }
            die("کێشەی پەیوەندی بە داتابەیس: " . $e->getMessage());
        }
        $this->assertCoreSchema();
    }

    /**
     * The database connected but may be empty or half-restored (dropped DB
     * recreated by hand, crashed restore, fresh install). If the core tables
     * are missing, every page would crash with "table doesn't exist" — send
     * the browser to the standalone recovery page instead.
     */
    private function assertCoreSchema() {
        if (defined('RECOVERY_MODE') || defined('BACKUP_CRON') || PHP_SAPI === 'cli') {
            return;
        }
        // Answered from the cached table set (see loadTableSet) so a healthy
        // site costs zero queries here. Previously this ran an
        // information_schema lookup on every single request.
        $set = $this->loadTableSet();
        if ($set) {
            require_once __DIR__ . '/SchemaVerifier.php';
            foreach (SchemaVerifier::CORE_TABLES as $t) {
                if (!isset($set[strtolower($t)])) {
                    self::redirectToRecovery('missing_tables');
                }
            }
            return;
        }
        // Empty set means SHOW TABLES failed or the database really is empty —
        // fall back to the authoritative check before bouncing anyone.
        require_once __DIR__ . '/SchemaVerifier.php';
        if (SchemaVerifier::coreOk($this->connection)) {
            return;
        }
        self::redirectToRecovery('missing_tables');
    }

    /** Send the client to recovery.php (JSON hint for AJAX callers). */
    private static function redirectToRecovery($reason) {
        if (PHP_SAPI === 'cli' || defined('BACKUP_CRON')) {
            die("Database not ready ({$reason}). Open recovery.php in a browser to restore a backup.\n");
        }
        $url = (defined('APP_URL') ? APP_URL : '') . '/recovery.php?reason=' . rawurlencode($reason);
        $isAjax = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')
            || strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
        if ($isAjax) {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success'    => false,
                'db_missing' => true,
                'redirect'   => $url,
                'message'    => 'داتابەیس بەردەست نییە — پێویستە بگەڕێندرێتەوە',
            ], JSON_UNESCAPED_UNICODE);
        } else {
            header('Location: ' . $url);
        }
        exit;
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->connection;
    }

    public function query($sql, $params = []) {
        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function fetch($sql, $params = []) {
        return $this->query($sql, $params)->fetch();
    }

    public function fetchAll($sql, $params = []) {
        return $this->query($sql, $params)->fetchAll();
    }

    public function insert($table, $data) {
        $columns = implode(', ', array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));
        $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
        $this->query($sql, $data);
        return $this->connection->lastInsertId();
    }

    public function update($table, $data, $where, $whereParams = []) {
        $set = [];
        foreach (array_keys($data) as $column) {
            $set[] = "{$column} = :{$column}";
        }
        $set = implode(', ', $set);

        // Convert positional ? in WHERE to named params (:_w0, :_w1, …)
        // so PDO never sees mixed named + positional parameters.
        $namedWhere = [];
        $i = 0;
        $where = preg_replace_callback('/\?/', function() use (&$i, $whereParams, &$namedWhere) {
            $key = '_w' . $i;
            $namedWhere[$key] = $whereParams[$i];
            $i++;
            return ':' . $key;
        }, $where);

        // Support already-named where params (assoc array) passed directly
        foreach ($whereParams as $k => $v) {
            if (!is_int($k)) {
                $namedWhere[$k] = $v;
            }
        }

        $sql = "UPDATE {$table} SET {$set} WHERE {$where}";
        return $this->query($sql, array_merge($data, $namedWhere));
    }

    public function delete($table, $where, $params = []) {
        $sql = "DELETE FROM {$table} WHERE {$where}";
        return $this->query($sql, $params);
    }

    // ── Read-only count memo ──────────────────────────────────────────
    // header.php and sidebar.php render the same badges and were issuing the
    // identical COUNT(*) two or three times per page (waiting_room,
    // accounting_reports, today's appointments …). Between beginReadOnlyBatch()
    // and endReadOnlyBatch() an identical count is executed once and reused.
    //
    // Deliberately opt-in and short-lived: it is only ever switched on around
    // the badge block, which performs no writes, so it can never serve a value
    // that a write in the same request has already invalidated.

    private $countMemo = null;   // null = memo disabled

    public function beginReadOnlyBatch() {
        $this->countMemo = [];
    }

    public function endReadOnlyBatch() {
        $this->countMemo = null;
    }

    public function count($table, $where = '1=1', $params = []) {
        $sql = "SELECT COUNT(*) as count FROM {$table} WHERE {$where}";
        if ($this->countMemo === null) {
            return $this->fetch($sql, $params)['count'];
        }
        $key = $sql . '|' . json_encode($params);
        if (!array_key_exists($key, $this->countMemo)) {
            $this->countMemo[$key] = $this->fetch($sql, $params)['count'];
        }
        return $this->countMemo[$key];
    }

    // ── Table-existence cache ─────────────────────────────────────────
    // tableExists() used to hit information_schema every call (~2 ms each).
    // header.php alone calls it twice on every page load, and other pages
    // call it repeatedly. The set of tables only changes when a migration
    // or a restore runs, so it is cached on disk and invalidated by the
    // mtime of storage/migrations.lock — which MigrationRunner rewrites on
    // any migration change and RestoreManager deletes after a restore.
    //
    // Only positive results are cached. A table missing from the cache is
    // still verified against the database, so a table created at runtime is
    // never wrongly reported absent.

    private $tableSet = null;   // ['tablename' => true], lowercase keys

    private function schemaCachePath() {
        return dirname(__DIR__) . '/storage/schema_tables.cache';
    }

    private function schemaCacheStamp() {
        return (string)(@filemtime(dirname(__DIR__) . '/storage/migrations.lock') ?: 0);
    }

    /** Load the cached table set for this request, rebuilding it if stale. */
    private function loadTableSet() {
        if ($this->tableSet !== null) {
            return $this->tableSet;
        }

        $cacheFile = $this->schemaCachePath();
        $stamp     = $this->schemaCacheStamp();
        $raw       = @file_get_contents($cacheFile);
        if ($raw !== false) {
            $data = json_decode($raw, true);
            if (is_array($data) && ($data['stamp'] ?? null) === $stamp && isset($data['tables'])) {
                return $this->tableSet = $data['tables'];
            }
        }

        // Stale or absent — rebuild with a single round trip.
        $this->tableSet = [];
        try {
            $names = $this->connection->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($names as $n) {
                $this->tableSet[strtolower($n)] = true;
            }
            @file_put_contents(
                $cacheFile,
                json_encode(['stamp' => $stamp, 'tables' => $this->tableSet]),
                LOCK_EX
            );
        } catch (PDOException $e) {
            // Leave the set empty — every lookup falls through to a live check.
        }
        return $this->tableSet;
    }

    /** Record a table discovered after the cache was built. */
    private function rememberTable($key) {
        $this->tableSet[$key] = true;
        $raw = @file_get_contents($this->schemaCachePath());
        $data = $raw !== false ? json_decode($raw, true) : null;
        if (!is_array($data) || !isset($data['tables'])) {
            $data = ['stamp' => $this->schemaCacheStamp(), 'tables' => []];
        }
        $data['tables'][$key] = true;
        @file_put_contents($this->schemaCachePath(), json_encode($data), LOCK_EX);
    }

    public function tableExists($table) {
        $key = strtolower((string)$table);
        if (isset($this->loadTableSet()[$key])) {
            return true;
        }
        // Cache miss — the table may have been created since the cache was
        // built, so confirm against the database before answering "no".
        $stmt = $this->connection->prepare("
            SELECT COUNT(*) AS count
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table
        ");
        $stmt->execute(['table' => $table]);
        $exists = (int)$stmt->fetch()['count'] > 0;
        if ($exists) {
            $this->rememberTable($key);
        }
        return $exists;
    }

    public function lastInsertId() {
        return $this->connection->lastInsertId();
    }

    /**
     * Nested transactions via SAVEPOINT. Depth 1 = real transaction; deeper
     * begin/commit/rollback pairs map to savepoints, so an inner commit no
     * longer commits the caller's transaction and an inner rollback only
     * undoes the inner unit of work (e.g. installment auto-allocation)
     * without discarding the caller's earlier writes.
     */
    private $txDepth = 0;

    public function beginTransaction() {
        if (!$this->connection->inTransaction()) {
            $this->txDepth = 1;
            return $this->connection->beginTransaction();
        }
        // Already inside a transaction (ours, or one started directly on the
        // PDO handle) — nest via savepoint instead of erroring.
        if ($this->txDepth === 0) {
            $this->txDepth = 1;
        }
        $this->txDepth++;
        $this->connection->exec("SAVEPOINT sp_{$this->txDepth}");
        return true;
    }

    public function commit() {
        if (!$this->connection->inTransaction()) {
            $this->txDepth = 0;
            return true;
        }
        if ($this->txDepth > 1) {
            $this->connection->exec("RELEASE SAVEPOINT sp_{$this->txDepth}");
            $this->txDepth--;
            return true;
        }
        $this->txDepth = 0;
        return $this->connection->commit();
    }

    public function rollback() {
        if (!$this->connection->inTransaction()) {
            $this->txDepth = 0;
            return true;
        }
        if ($this->txDepth > 1) {
            $this->connection->exec("ROLLBACK TO SAVEPOINT sp_{$this->txDepth}");
            $this->txDepth--;
            return true;
        }
        $this->txDepth = 0;
        return $this->connection->rollBack();
    }

    public function inTransaction() {
        return $this->connection->inTransaction();
    }

    // Prevent cloning
    private function __clone() {}

    // Prevent unserialization
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}
