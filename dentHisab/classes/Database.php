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
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
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

    public function count($table, $where = '1=1', $params = []) {
        $sql = "SELECT COUNT(*) as count FROM {$table} WHERE {$where}";
        return $this->fetch($sql, $params)['count'];
    }

    public function tableExists($table) {
        $stmt = $this->connection->prepare("
            SELECT COUNT(*) AS count
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table
        ");
        $stmt->execute(['table' => $table]);
        return (int)$stmt->fetch()['count'] > 0;
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
