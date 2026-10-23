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

        // Re-check: if every file on disk is recorded, write/update the lock.
        // Compare the actual file names — NOT the counts. A migration whose
        // .sql file was later deleted or renamed stays in schema_migrations
        // forever, so count(applied) > count(files) and a count comparison
        // would never be true again: the lock would never be written and
        // every single request would re-run this whole slow path.
        $appliedAfter = array_flip($this->getAppliedMigrations());
        $allApplied   = true;
        foreach ($files as $file) {
            if (!isset($appliedAfter[basename($file)])) {
                $allApplied = false;
                break;
            }
        }
        if ($allApplied) {
            $this->writeLock($currentHash);
        }
    }

    // ── Lock file helpers ─────────────────────────────────────────────

    /**
     * Hash is tied to the current database and the migration file set.
     *
     * Uses each file's name + size + mtime rather than sha1_file(): hashing
     * the contents meant reading all ~72 .sql files off disk on EVERY request
     * (measured at 38 ms) just to decide there was nothing to do. Size+mtime
     * changes whenever a file is edited, added, renamed or removed, which is
     * exactly what this lock needs to detect — and it costs a stat() instead
     * of a full read.
     */
    private function hashFiles(array $files): string {
        $parts = [DB_HOST, DB_NAME];
        foreach ($files as $file) {
            $parts[] = basename($file) . ':' . (@filesize($file) ?: 0) . ':' . (@filemtime($file) ?: 0);
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
     *
     * Scans character by character while tracking quote and comment state, so
     * a ';' only ends a statement when it is genuinely between statements.
     *
     * The previous version stripped anything after the first '--' on a line
     * and then split on every ';'. That silently corrupted two real cases:
     *   - a ';' inside a block comment split the COMMENT in half, and the
     *     tail was handed to MySQL as if it were SQL (syntax error 1064)
     *   - a ';' or '--' inside a quoted string, e.g. a seeded Kurdish note,
     *     truncated the INSERT mid-value
     * Both failed loudly here, but a migration that half-applies is the kind
     * of thing that leaves a schema subtly wrong, so the scanner is worth it.
     *
     * '/*!' MySQL version hints are executable, not comments, and are passed
     * through untouched.
     */
    private function splitStatements(string $sql): array {
        $statements = [];
        $buffer     = '';
        $len        = strlen($sql);
        $i          = 0;

        while ($i < $len) {
            $ch   = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';

            // Quoted string or quoted identifier: copy verbatim to its close.
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote   = $ch;
                $buffer .= $ch;
                $i++;
                while ($i < $len) {
                    $c = $sql[$i];
                    // Backslash escape (MySQL default, not ANSI_QUOTES)
                    if ($c === '\\' && $quote !== '`' && $i + 1 < $len) {
                        $buffer .= $c . $sql[$i + 1];
                        $i += 2;
                        continue;
                    }
                    // '' inside a '-string is a literal quote, not the end
                    if ($c === $quote && ($i + 1 < $len) && $sql[$i + 1] === $quote) {
                        $buffer .= $c . $sql[$i + 1];
                        $i += 2;
                        continue;
                    }
                    $buffer .= $c;
                    $i++;
                    if ($c === $quote) {
                        break;
                    }
                }
                continue;
            }

            // Block comment. '/*!' is a version hint and must survive.
            if ($ch === '/' && $next === '*') {
                if (($i + 2 < $len) && $sql[$i + 2] === '!') {
                    $buffer .= $ch;
                    $i++;
                    continue;
                }
                $end = strpos($sql, '*/', $i + 2);
                $i   = ($end === false) ? $len : $end + 2;
                $buffer .= ' ';
                continue;
            }

            // Line comment: '-- ' or '#' through end of line.
            if (($ch === '-' && $next === '-') || $ch === '#') {
                $end = strpos($sql, "\n", $i);
                $i   = ($end === false) ? $len : $end;
                continue;
            }

            if ($ch === ';') {
                $statements[] = $buffer;
                $buffer = '';
                $i++;
                continue;
            }

            $buffer .= $ch;
            $i++;
        }
        $statements[] = $buffer;

        // MySQL 5.7 doesn't support ADD COLUMN IF NOT EXISTS
        $statements = array_map(
            fn($s) => preg_replace('/\bADD\s+COLUMN\s+IF\s+NOT\s+EXISTS\s+/i', 'ADD COLUMN ', $s),
            $statements
        );

        return array_values(array_filter(
            array_map('trim', $statements),
            fn($s) => $s !== ''
        ));
    }

    private function record(string $name): void {
        $stmt = $this->pdo->prepare(
            "INSERT IGNORE INTO schema_migrations (migration_name) VALUES (?)"
        );
        $stmt->execute([$name]);
    }
}
