<?php
/**
 * RestoreManager - Safe database restore
 * سیستەمی زیرەکی کلینیکی ددان
 *
 * Restores a backup created by BackupManager (or any mysqldump file):
 *   - reads .sql, .sql.gz and .zip streams without loading them into memory
 *   - statement parser understands strings, comments, backticks and
 *     DELIMITER blocks (triggers / procedures / functions / events)
 *   - optional automatic pre-restore backup and maintenance mode
 *   - DEFINER clauses are stripped so dumps restore across hosts/users
 *   - collations/charsets the server doesn't know (utf8mb4_0900_* from
 *     MySQL 8, utf8mb4_uca1400_* from new MariaDB, utf8mb3) are rewritten
 *     to universal equivalents, so dumps restore across server families
 *   - progress is written to the shared progress files of BackupManager
 *
 * After a successful restore the migrations lock file is removed so
 * MigrationRunner re-checks the schema on the next request (the restored
 * dump may be older than the current code).
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/AuditLog.php';
require_once __DIR__ . '/BackupManager.php';
require_once __DIR__ . '/SqlCompat.php';
require_once __DIR__ . '/SchemaVerifier.php';

class RestoreManager {

    private $db;
    private $progressToken = null;

    /** old => new collation names rewritten during this restore. */
    private $compatFixes = [];
    /** Sample of statements skipped as already-applied, plus the total count. */
    private $skipped = [];
    private $skippedCount = 0;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    // ── Maintenance mode ──────────────────────────────────────────────

    public static function maintenanceFile() {
        return APP_ROOT . '/storage/maintenance.flag';
    }

    public static function enableMaintenance($userId = null) {
        file_put_contents(self::maintenanceFile(), json_encode([
            'enabled_at' => date('Y-m-d H:i:s'),
            'by_user'    => $userId,
            'reason'     => 'database_restore',
        ]), LOCK_EX);
    }

    public static function disableMaintenance() {
        $file = self::maintenanceFile();
        if (file_exists($file)) {
            @unlink($file);
        }
    }

    public static function isMaintenanceActive() {
        $file = self::maintenanceFile();
        if (!file_exists($file)) {
            return false;
        }
        // A flag older than an hour means a restore crashed — auto-recover.
        if (time() - (int)filemtime($file) > 3600) {
            @unlink($file);
            return false;
        }
        return true;
    }

    // ── Restore ───────────────────────────────────────────────────────

    /**
     * Restore a backup.
     *
     * $opts:
     *   id            backup_history id to restore, or
     *   path          absolute path to a dump file (internal/cron/tests only)
     *   pre_backup    bool - take a full backup first (default true)
     *   maintenance   bool - block non-admins while restoring (default true)
     *   user_id       acting user id or null
     *   progress      progress token from the client (optional)
     */
    public function restore(array $opts = []) {
        $this->progressToken = $opts['progress'] ?? null;
        $userId  = isset($opts['user_id']) ? (int)$opts['user_id'] : null;
        $preBak  = !array_key_exists('pre_backup', $opts) || (bool)$opts['pre_backup'];
        $maint   = !array_key_exists('maintenance', $opts) || (bool)$opts['maintenance'];

        $backupManager = new BackupManager();
        $historyRow    = null;

        if (isset($opts['id'])) {
            $historyRow = $backupManager->getById((int)$opts['id']);
            if (!$historyRow || $historyRow['status'] !== 'completed') {
                return ['success' => false, 'message' => 'باکئەپەکە نەدۆزرایەوە یان تەواو نییە'];
            }
            $path = $backupManager->filePathFor($historyRow);
            if ($path === null || !is_file($path)) {
                return ['success' => false, 'message' => 'فایلی باکئەپەکە لەسەر دیسک نەدۆزرایەوە'];
            }
        } elseif (isset($opts['path'])) {
            $path = (string)$opts['path'];
            if (!is_file($path)) {
                return ['success' => false, 'message' => 'فایلی دیاریکراو بوونی نییە'];
            }
        } else {
            return ['success' => false, 'message' => 'هیچ سەرچاوەیەکی گەڕاندنەوە دیاری نەکراوە'];
        }

        // 1) Automatic safety backup before touching anything.
        if ($preBak) {
            $this->progress('pre_backup', 2, 'باکئەپی خۆکاری پێش گەڕاندنەوە...');
            $pre = $backupManager->create([
                'type'        => 'full',
                'compression' => 'gzip',
                'trigger'     => 'pre_restore',
                'user_id'     => $userId,
            ]);
            if (empty($pre['success'])) {
                return ['success' => false, 'message' => 'باکئەپی پاراستن سەرکەوتوو نەبوو، گەڕاندنەوە ڕاگیرا: ' . ($pre['message'] ?? '')];
            }
        }

        // 2) Maintenance mode while the data is inconsistent.
        if ($maint) {
            self::enableMaintenance($userId);
        }

        $tmpExtracted = null;
        try {
            [$readPath, $tmpExtracted] = $this->materializeSql($path);
            $totalBytes = $this->uncompressedSize($readPath);

            $this->progress('restoring', 5, 'گەڕاندنەوەی داتابەیس دەستیپێکرد...');
            $result = $this->executeSqlFile($readPath, $totalBytes);

            // 3) Post-restore housekeeping.
            $lock = APP_ROOT . '/storage/migrations.lock';
            if (file_exists($lock)) {
                @unlink($lock); // force MigrationRunner to re-check the restored schema
            }
            try {
                $backupManager->reconcileFiles(); // restored history may miss newer files
            } catch (Exception $e) {
                // backup_history table may not exist in a foreign dump — not fatal
            }

            // 4) Verify the restored schema before anyone is let back in.
            $this->progress('verifying', 98, 'پشکنینی خشتەکان دوای گەڕاندنەوە...');
            $verify = SchemaVerifier::verify($this->db->getConnection());

            AuditLog::log('restore_database', 'backup_history',
                $historyRow ? (int)$historyRow['id'] : null, null, [
                    'file'         => $historyRow['file_name'] ?? basename($path),
                    'statements'   => $result['statements'],
                    'pre_backup'   => $preBak,
                    'skipped'      => $this->skippedCount,
                    'compat_fixes' => $this->compatFixes,
                    'schema_ok'    => $verify['ok'],
                ], $userId);

            $this->progress('done', 100, 'گەڕاندنەوە تەواو بوو', [
                'done' => true, 'success' => true, 'statements' => $result['statements'],
                'skipped' => $this->skippedCount, 'schema_ok' => $verify['ok'],
            ]);
            return [
                'success'      => true,
                'statements'   => $result['statements'],
                'file'         => $historyRow['file_name'] ?? basename($path),
                'skipped'      => $this->skippedCount,
                'skipped_list' => $this->skipped,
                'compat_fixes' => $this->compatFixes,
                'verify'       => $verify,
            ];
        } catch (Throwable $e) {
            AuditLog::log('restore_failed', 'backup_history',
                $historyRow ? (int)$historyRow['id'] : null, null,
                ['error' => mb_substr($e->getMessage(), 0, 500)], $userId);
            $this->progress('error', 100, $e->getMessage(), [
                'done' => true, 'success' => false, 'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => $e->getMessage()];
        } finally {
            if ($tmpExtracted !== null && file_exists($tmpExtracted)) {
                @unlink($tmpExtracted);
            }
            if ($maint) {
                self::disableMaintenance();
            }
        }
    }

    /** Zip archives are extracted to a temp .sql; .sql/.sql.gz are read directly. */
    private function materializeSql($path) {
        if (strtolower(substr($path, -4)) !== '.zip') {
            return [$path, null];
        }
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('ext-zip لەم سێرڤەرە بەردەست نییە');
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('نەتوانرا فایلی zip بکرێتەوە');
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if (strtolower(substr($entry, -4)) === '.sql' && strpos($entry, '..') === false) {
                $tmp = BackupManager::backupDir() . '/.tmp/restore_' . bin2hex(random_bytes(6)) . '.sql';
                $in  = $zip->getStream($entry);
                $out = fopen($tmp, 'wb');
                if (!$in || !$out) {
                    $zip->close();
                    throw new RuntimeException('نەتوانرا ناوەڕۆکی zip دەربهێنرێت');
                }
                stream_copy_to_stream($in, $out);
                fclose($in);
                fclose($out);
                $zip->close();
                return [$tmp, $tmp];
            }
        }
        $zip->close();
        throw new RuntimeException('هیچ فایلێکی .sql لەناو zip ەکە نەدۆزرایەوە');
    }

    /** Uncompressed byte count for progress (gzip stores it in the last 4 bytes). */
    private function uncompressedSize($path) {
        if (strtolower(substr($path, -3)) === '.gz') {
            $f = fopen($path, 'rb');
            if ($f && fseek($f, -4, SEEK_END) === 0) {
                $data = fread($f, 4);
                fclose($f);
                if (strlen($data) === 4) {
                    return max(1, unpack('V', $data)[1]); // ISIZE mod 2^32
                }
            }
            if ($f) {
                fclose($f);
            }
            return max(1, (int)filesize($path) * 4); // fallback guess
        }
        return max(1, (int)filesize($path));
    }

    /**
     * Stream the dump and execute it statement by statement.
     * The parser tracks strings / comments / backticks / DELIMITER so that
     * semicolons inside data or trigger bodies never split a statement.
     */
    private function executeSqlFile($path, $totalBytes) {
        $pdo = $this->db->getConnection();

        // Safety net even when the dump lacks its own SET block.
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        $pdo->exec("SET UNIQUE_CHECKS = 0");
        $pdo->exec("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'");
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

        $gz = gzopen($path, 'rb'); // transparently reads plain .sql too
        if ($gz === false) {
            throw new RuntimeException('نەتوانرا فایلی باکئەپ بخوێنرێتەوە');
        }

        $stmt       = '';
        $state      = 'code'; // code | squote | dquote | btick | linecomment | blockcomment
        $delimiter  = ';';
        $statements = 0;
        $lastTick   = 0;
        $prevStar   = false; // "*" seen as last char of previous chunk (blockcomment end)

        try {
            while (!gzeof($gz)) {
                $chunk = gzread($gz, 262144);
                if ($chunk === false) {
                    throw new RuntimeException('خوێندنەوەی فایلەکە شکستی هێنا (فایلی تێکچوو؟)');
                }
                $len = strlen($chunk);

                for ($i = 0; $i < $len; $i++) {
                    $ch = $chunk[$i];

                    if ($state === 'linecomment') {
                        if ($ch === "\n") {
                            $state = 'code';
                            // a DELIMITER command may sit alone on this line
                            $this->maybeDelimiter($stmt, $delimiter);
                        }
                        continue;
                    }
                    if ($state === 'blockcomment') {
                        if ($ch === '/' && ($i > 0 ? $chunk[$i - 1] === '*' : $prevStar)) {
                            $state = 'code';
                        }
                        $prevStar = ($ch === '*');
                        continue;
                    }
                    if ($state === 'squote' || $state === 'dquote') {
                        $stmt .= $ch;
                        if ($ch === '\\') {
                            // consume the escaped char (may sit in the next chunk)
                            if ($i + 1 < $len) {
                                $stmt .= $chunk[++$i];
                            } else {
                                $next = gzread($gz, 1);
                                if ($next !== false && $next !== '') {
                                    $stmt .= $next;
                                }
                            }
                        } elseif (($state === 'squote' && $ch === "'") || ($state === 'dquote' && $ch === '"')) {
                            $state = 'code';
                        }
                        continue;
                    }
                    if ($state === 'btick') {
                        $stmt .= $ch;
                        if ($ch === '`') {
                            $state = 'code';
                        }
                        continue;
                    }

                    // state === 'code'
                    // A "DELIMITER xx" client command is terminated by newline,
                    // never by the delimiter itself (xx usually contains ';').
                    if ($ch === $delimiter[0] && stripos(ltrim($stmt), 'DELIMITER') === 0) {
                        $stmt .= $ch;
                        continue;
                    }
                    if ($ch === $delimiter[0]) {
                        $dlen = strlen($delimiter);
                        $rest = $delimiter;
                        if ($dlen > 1) {
                            // peek the remaining delimiter chars (chunk-boundary safe)
                            $have = substr($chunk, $i, $dlen);
                            while (strlen($have) < $dlen && !gzeof($gz)) {
                                $more = gzread($gz, $dlen - strlen($have));
                                if ($more === false || $more === '') break;
                                $chunk .= $more;
                                $len   += strlen($more);
                                $have   = substr($chunk, $i, $dlen);
                            }
                            $rest = $have;
                        }
                        if ($rest === $delimiter) {
                            $i += strlen($delimiter) - 1;
                            $statements += $this->executeStatement($pdo, $stmt);
                            $stmt = '';
                            continue;
                        }
                    }

                    if ($ch === "'") {
                        $state = 'squote';
                        $stmt .= $ch;
                        continue;
                    }
                    if ($ch === '"') {
                        $state = 'dquote';
                        $stmt .= $ch;
                        continue;
                    }
                    if ($ch === '`') {
                        $state = 'btick';
                        $stmt .= $ch;
                        continue;
                    }
                    if ($ch === '#') {
                        $state = 'linecomment';
                        continue;
                    }
                    if ($ch === '-' && ($i + 1) < $len && $chunk[$i + 1] === '-') {
                        $after = ($i + 2) < $len ? $chunk[$i + 2] : "\n";
                        if ($after === ' ' || $after === "\t" || $after === "\n" || $after === "\r") {
                            $state = 'linecomment';
                            $i++; // skip the second dash
                            continue;
                        }
                    }
                    if ($ch === '/' && ($i + 1) < $len && $chunk[$i + 1] === '*') {
                        // /*! conditional comments are real SQL — keep them
                        $bang = ($i + 2) < $len ? $chunk[$i + 2] : '';
                        if ($bang !== '!') {
                            $state = 'blockcomment';
                            $i++;
                            continue;
                        }
                    }
                    if ($ch === "\n") {
                        if ($this->maybeDelimiter($stmt, $delimiter)) {
                            continue;
                        }
                    }
                    $stmt .= $ch;
                }

                if (time() !== $lastTick) {
                    $lastTick = time();
                    $pct = 5 + (int)(min(1, gztell($gz) / $totalBytes) * 93);
                    $this->progress('restoring', $pct, 'گەڕاندنەوە... ' . number_format($statements) . ' فەرمان جێبەجێکرا');
                }
            }

            // whatever is left after the final delimiter
            $statements += $this->executeStatement($pdo, $stmt);
        } finally {
            gzclose($gz);
            try {
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
                $pdo->exec("SET UNIQUE_CHECKS = 1");
            } catch (Exception $e) {
                // connection may be gone — nothing more to do
            }
        }

        return ['statements' => $statements];
    }

    /** Detect a client-side "DELIMITER xx" line; returns true when it consumed the buffer. */
    private function maybeDelimiter(&$stmt, &$delimiter) {
        $trimmed = ltrim($stmt);
        if ($trimmed !== '' && stripos($trimmed, 'DELIMITER ') === 0) {
            $new = trim(substr($trimmed, 10));
            if ($new !== '') {
                $delimiter = $new;
                $stmt = '';
                return true;
            }
        }
        return false;
    }

    /** Execute one statement; returns 1 when something ran, 0 for blanks and skips. */
    private function executeStatement(PDO $pdo, $sql) {
        $sql = trim($sql);
        if ($sql === '') {
            return 0;
        }
        // DEFINER clauses fail on hosts where that user doesn't exist.
        if (stripos($sql, 'DEFINER') !== false) {
            $sql = preg_replace('/\sDEFINER=`[^`]*`@`[^`]*`/i', ' ', $sql);
            $sql = preg_replace('/\sDEFINER=[^\s*]+/i', ' ', $sql);
        }

        // Row data is passed through untouched. An INSERT may legitimately carry
        // a collation name inside a text column (a note, a settings value), and
        // rewriting it there would silently corrupt patient data.
        $isData = SqlCompat::isDataStatement($sql);
        if (!$isData) {
            $sql = SqlCompat::rewrite($sql, $pdo, $this->compatFixes);
        }

        for ($attempt = 0; ; $attempt++) {
            try {
                $pdo->exec($sql);
                return 1;
            } catch (PDOException $e) {
                // "Unknown collation/character set" — dump came from a different
                // server family; rewrite the offending name and try again.
                $fixed = (!$isData && $attempt < self::MAX_RESCUE_ATTEMPTS)
                    ? SqlCompat::rescue($sql, $e->getMessage(), $this->compatFixes)
                    : null;
                if ($fixed !== null) {
                    $sql = $fixed;
                    continue;
                }
                if ($this->isSkippable($e)) {
                    $this->recordSkipped($sql, $e);
                    return 0;
                }
                $snippet = mb_substr(preg_replace('/\s+/', ' ', $sql), 0, 160);
                throw new RuntimeException('هەڵە لە فەرمانی SQL: ' . $e->getMessage() . ' — «' . $snippet . '…»');
            }
        }
    }

    // ── Skippable statements ──────────────────────────────────────────
    //
    // Restoring over an existing schema re-runs DDL that is already in place.
    // Only errors that mean "this object is already in the state the dump wants"
    // are skipped, and every one is reported to the admin. Anything that could
    // mean data loss (unknown table on INSERT, duplicate primary key, FK
    // violation, disk/permission errors) still aborts the restore.

    /** MySQL error codes that are safe to skip when re-applying DDL. */
    const SKIPPABLE_ERRORS = [
        1007, // database exists
        1050, // table already exists
        1051, // unknown table (DROP TABLE on a fresh database)
        1060, // duplicate column name
        1061, // duplicate key name
        1091, // can't DROP; doesn't exist
        1305, // procedure/function does not exist (DROP)
        1359, // trigger already exists
        1360, // trigger does not exist (DROP)
    ];

    const MAX_RESCUE_ATTEMPTS = 6;

    private function isSkippable(PDOException $e) {
        $code = (int)($e->errorInfo[1] ?? 0);
        return in_array($code, self::SKIPPABLE_ERRORS, true);
    }

    private function recordSkipped($sql, PDOException $e) {
        $this->skippedCount++;
        // Keep a bounded sample: a broken dump could otherwise produce thousands.
        if (count($this->skipped) < 50) {
            $this->skipped[] = [
                'error'     => (int)($e->errorInfo[1] ?? 0),
                'message'   => mb_substr($e->getMessage(), 0, 200),
                'statement' => mb_substr(preg_replace('/\s+/', ' ', $sql), 0, 160),
            ];
        }
    }

    private function progress($stage, $percent, $message, array $extra = []) {
        if ($this->progressToken === null) {
            return;
        }
        BackupManager::writeProgress($this->progressToken, array_merge([
            'stage'   => $stage,
            'percent' => max(0, min(100, (int)round($percent))),
            'message' => $message,
            'done'    => false,
        ], $extra));
    }
}
