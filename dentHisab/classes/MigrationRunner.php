<?php
/**
 * MigrationRunner
 * Auto-applies pending SQL migrations on startup.
 *
 * Performance strategy (lock file):
 *   - Computes a hash of all migration filenames in the migrations folder.
 *   - Stores the hash in storage/migrations.lock after all are applied.
 *   - On each request: if the hash matches the lock file → skip entirely (no DB query).
 *   - If a new .sql file is added the hash changes → runs the DB check once → updates lock.
 */
class MigrationRunner {

    private PDO    $pdo;
    private string $migrationsDir;
    private string $lockFile;

    // MySQL error codes that mean "already done" — safe to ignore
    private const IGNORABLE_ERRORS = [
        1060, // Duplicate column name
        1061, // Duplicate key name
        1050, // Table already exists
        1054, // Unknown column
        1091, // Can't DROP; column/key doesn't exist
        1062, // Duplicate entry (unique key — data already seeded)
        1265, // Data truncated for column
        1826, // Duplicate foreign key constraint name — FK already added
    ];

    public function __construct(PDO $pdo, string $migrationsDir, string $storageDir) {
        $this->pdo           = $pdo;
        $this->migrationsDir = rtrim($migrationsDir, '/\\');
        $this->lockFile      = rtrim($storageDir, '/\\') . DIRECTORY_SEPARATOR . 'migrations.lock';
    }

    /**
     * Entry point — safe to call on every request.
     * Skips all DB work if the lock file is up to date.
     */
    public function run(): void {
        $files        = $this->getMigrationFiles();
        $currentHash  = $this->hashFiles($files);

        // Fast path: lock matches → nothing to do
        if ($this->isLocked($currentHash)) {
            return;
        }

        // Slow path: new migration detected → check DB and apply pending
        $this->ensureMigrationsTable();
        $applied = $this->getAppliedMigrations();
        $pending = false;

        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $applied, true)) {
                continue;
            }
            $this->applyMigration($name, $file);
            $pending = true;
        }

        // Re-check: if all migrations are now applied, write/update the lock
        $appliedAfter = $this->getAppliedMigrations();
        $allApplied   = count($appliedAfter) === count($files);
        if ($allApplied) {
            $this->writeLock($currentHash);
        }
    }

    // ── Lock file helpers ─────────────────────────────────────────────

    /** Hash is tied to the current database and migration file contents. */
    private function hashFiles(array $files): string {
        $parts = [DB_HOST, DB_NAME];
        foreach ($files as $file) {
            $parts[] = basename($file) . ':' . (sha1_file($file) ?: '');
        }
        return sha1(implode('|', $parts));
    }

    private function isLocked(string $currentHash): bool {
        if (!file_exists($this->lockFile)) {
            return false;
        }
        return trim(file_get_contents($this->lockFile)) === $currentHash;
    }

    private function writeLock(string $hash): void {
        file_put_contents($this->lockFile, $hash, LOCK_EX);
    }

    // ── DB helpers ────────────────────────────────────────────────────

    private function ensureMigrationsTable(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS schema_migrations (
                id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                migration_name VARCHAR(255) NOT NULL UNIQUE,
                applied_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    private function getAppliedMigrations(): array {
        $stmt = $this->pdo->query("SELECT migration_name FROM schema_migrations");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Return sorted list of .sql files. */
    private function getMigrationFiles(): array {
        $files = glob($this->migrationsDir . DIRECTORY_SEPARATOR . '*.sql') ?: [];
        sort($files);
        return $files;
    }

    private function applyMigration(string $name, string $file): void {
        $sql = file_get_contents($file);
        if ($sql === false || trim($sql) === '') {
            $this->record($name);
            return;
        }

        // Restore correct DB in case a migration file contains USE other_db
        $this->pdo->exec("USE `" . DB_NAME . "`");

        $hadFatalError = false;

        foreach ($this->splitStatements($sql) as $stmt) {
            if (trim($stmt) === '') continue;
            // Skip USE statements — PDO is already on the correct DB
            if (preg_match('/^\s*USE\s+/i', $stmt)) continue;

            try {
                $this->pdo->exec($stmt);
            } catch (PDOException $e) {
                $code = (int)($e->errorInfo[1] ?? 0);
                if (!in_array($code, self::IGNORABLE_ERRORS, true)) {
                    error_log("[MigrationRunner] Error in '{$name}': " . $e->getMessage());
                    $hadFatalError = true;
                }
            }
        }

        if (!$hadFatalError) {
            $this->record($name);
        }
    }

    /**
     * Split SQL into individual statements.
     * Strips -- comments and rewrites ADD COLUMN IF NOT EXISTS
     * for MySQL 5.7 compatibility (falls back to ignorable error 1060).
     */
    private function splitStatements(string $sql): array {
        $lines = explode("\n", $sql);
        $clean = [];
        foreach ($lines as $line) {
            $pos = strpos($line, '--');
            if ($pos !== false) {
                $line = substr($line, 0, $pos);
            }
            $clean[] = $line;
        }
        $sql = implode("\n", $clean);

        // MySQL 5.7 doesn't support ADD COLUMN IF NOT EXISTS
        $sql = preg_replace('/\bADD\s+COLUMN\s+IF\s+NOT\s+EXISTS\s+/i', 'ADD COLUMN ', $sql);

        return array_filter(
            array_map('trim', explode(';', $sql)),
            fn($s) => $s !== ''
        );
    }

    private function record(string $name): void {
        $stmt = $this->pdo->prepare(
            "INSERT IGNORE INTO schema_migrations (migration_name) VALUES (?)"
        );
        $stmt->execute([$name]);
    }
}
