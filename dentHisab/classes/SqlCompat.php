<?php
/**
 * SqlCompat - Cross-version MySQL / MariaDB SQL compatibility
 * سیستەمی زیرەکی کلینیکی ددان
 *
 * One source of truth for making dumps portable between server families.
 * A dump taken on MySQL 8 carries utf8mb4_0900_ai_ci; MariaDB rejects it with
 * "#1273 - Unknown collation". New MariaDB writes utf8mb4_uca1400_ai_ci, which
 * MySQL rejects the same way. This class rewrites every such name to a
 * collation that MySQL 5.5+ and every MariaDB understand.
 *
 * It is applied in two places:
 *   - BackupManager (write path)  — the .sql file itself is portable, so it
 *     also imports cleanly through phpMyAdmin / the mysql CLI, not just here.
 *   - RestoreManager (read path)  — foreign dumps and older backups still work.
 *
 * Rewriting only ever touches DDL. Row data is passed through byte-for-byte,
 * so a patient note that happens to contain the text "utf8mb4_0900_ai_ci" is
 * never modified.
 */

class SqlCompat {

    /** Collations every MySQL 5.5+ and every MariaDB build accepts. */
    const SAFE_COLLATION     = 'utf8mb4_unicode_ci';
    const SAFE_COLLATION_BIN = 'utf8mb4_bin';
    const SAFE_CHARSET       = 'utf8mb4';

    /**
     * Collation families that are not portable across server families.
     *   _0900_    MySQL 8 only          (utf8mb4_0900_ai_ci, utf8mb4_0900_as_cs, ...)
     *   _uca1400_ MariaDB 11.4+ only    (utf8mb4_uca1400_ai_ci, ...)
     *   _nopad_   MariaDB only          (utf8mb4_unicode_nopad_ci, ...)
     */
    const UNPORTABLE_PATTERN = '/\b[a-z0-9]+_(?:0900|uca1400)_[a-z0-9_]+\b|\b[a-z0-9]+_[a-z0-9]+_nopad_[a-z0-9_]+\b/i';

    /** Charset names to normalize. utf8mb3 is MySQL 8 spelling; "utf8" is understood everywhere. */
    const CHARSET_ALIASES = [
        'utf8mb3' => 'utf8',
    ];

    // ── Server detection ──────────────────────────────────────────────

    /**
     * Identify the server behind a PDO handle.
     *
     * Returns: flavor (mysql|mariadb), version, version_full, and the two
     * capability flags the restore UI needs to warn about.
     */
    public static function serverInfo(PDO $pdo) {
        $full = '';
        try {
            $full = (string)$pdo->query("SELECT VERSION()")->fetchColumn();
        } catch (Throwable $e) {
            try {
                $full = (string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
            } catch (Throwable $e2) {
                $full = '';
            }
        }

        // MariaDB reports e.g. "10.4.32-MariaDB" or "5.5.5-10.4.32-MariaDB".
        $isMaria = stripos($full, 'mariadb') !== false;
        $version = '0';
        if (preg_match_all('/(\d+\.\d+\.\d+)/', $full, $m) && !empty($m[1])) {
            // The 5.5.5- prefix MariaDB prepends for old-client compat is not the real version.
            $version = ($isMaria && count($m[1]) > 1 && $m[1][0] === '5.5.5') ? $m[1][1] : $m[1][0];
        }

        return [
            'flavor'          => $isMaria ? 'mariadb' : 'mysql',
            'flavor_label'    => $isMaria ? 'MariaDB' : 'MySQL',
            'version'         => $version,
            'version_full'    => $full !== '' ? $full : 'unknown',
            'supports_0900'   => !$isMaria && version_compare($version, '8.0.0', '>='),
            'supports_utf8mb4'=> self::hasCharset($pdo, 'utf8mb4'),
        ];
    }

    /** Human summary for the recovery UI, e.g. "MariaDB 10.4.32". */
    public static function serverLabel(PDO $pdo) {
        $info = self::serverInfo($pdo);
        return $info['flavor_label'] . ' ' . $info['version'];
    }

    // ── Capability probes (memoized per connection) ───────────────────

    private static $collationCache = [];
    private static $charsetCache   = [];

    public static function hasCollation(PDO $pdo, $name) {
        $key = strtolower($name);
        if (!array_key_exists($key, self::$collationCache)) {
            self::$collationCache[$key] = self::probe(
                $pdo, "SELECT COUNT(*) FROM information_schema.COLLATIONS WHERE COLLATION_NAME = ?", $key
            );
        }
        return self::$collationCache[$key];
    }

    public static function hasCharset(PDO $pdo, $name) {
        $key = strtolower($name);
        if (!array_key_exists($key, self::$charsetCache)) {
            self::$charsetCache[$key] = self::probe(
                $pdo, "SELECT COUNT(*) FROM information_schema.CHARACTER_SETS WHERE CHARACTER_SET_NAME = ?", $key
            );
        }
        return self::$charsetCache[$key];
    }

    private static function probe(PDO $pdo, $sql, $value) {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$value]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false; // information_schema unavailable — assume unsupported, rewrite is harmless
        }
    }

    /** Forget cached probes (used when the restore reconnects to a new database). */
    public static function resetCache() {
        self::$collationCache = [];
        self::$charsetCache   = [];
    }

    // ── Collation mapping ─────────────────────────────────────────────

    /**
     * Best universal stand-in for a collation the target can't use.
     *
     * _bin and _as_cs are case/accent sensitive; utf8mb4_unicode_ci is not, so
     * those map to utf8mb4_bin to keep comparison semantics rather than sorting.
     */
    public static function fallbackFor($collation) {
        $name = strtolower($collation);
        if (substr($name, -4) === '_bin' || strpos($name, '_as_cs') !== false) {
            return self::SAFE_COLLATION_BIN;
        }
        return self::SAFE_COLLATION;
    }

    /** True when this collation name cannot be relied on across server families. */
    public static function isUnportable($collation) {
        return (bool)preg_match(self::UNPORTABLE_PATTERN, $collation);
    }

    /**
     * Cheap pre-check: does this SQL text contain anything worth rewriting?
     * Lets callers skip the regex work on the overwhelming majority of statements.
     */
    public static function needsRewrite($sql) {
        return stripos($sql, '_0900_') !== false
            || stripos($sql, '_uca1400_') !== false
            || stripos($sql, '_nopad_') !== false
            || stripos($sql, 'utf8mb3') !== false;
    }

    // ── Rewriting ─────────────────────────────────────────────────────

    /**
     * Rewrite unportable collation/charset names in a DDL string.
     *
     * $pdo  when given, a name the server actually supports is left alone
     *       (restore path). Pass null to normalize unconditionally, which is
     *       what the backup path wants: the file must import anywhere, not
     *       just back into the server it came from.
     * $applied  collects "old => new" pairs for the report shown to the admin.
     */
    public static function rewrite($sql, PDO $pdo = null, array &$applied = []) {
        if (!self::needsRewrite($sql)) {
            return $sql;
        }

        // Only names introduced by one of these keywords are touched. Row data
        // never carries them, so a value that merely contains a collation name
        // cannot be rewritten — the mistake that would silently corrupt data.
        $pattern = '/(?P<prefix>\bCOLLATE\s*=?\s*|\bCOLLATION\s+|\bCHARACTER\s+SET\s*=?\s*'
                 . '|\bCHARSET\s*=\s*|\bSET\s+NAMES\s+)(?P<quote>[`\'"]?)(?P<name>[A-Za-z0-9_]+)(?P=quote)/i';

        return preg_replace_callback($pattern, function ($m) use ($pdo, &$applied) {
            $name    = strtolower($m['name']);
            $isChar  = (bool)preg_match('/CHARACTER|CHARSET|NAMES/i', $m['prefix']);
            $replace = $isChar ? self::charsetReplacement($name, $pdo) : self::collationReplacement($name, $pdo);

            if ($replace === null) {
                return $m[0];
            }
            $applied[$name] = $replace;
            return $m['prefix'] . $m['quote'] . $replace . $m['quote'];
        }, $sql);
    }

    /** Replacement for a charset name, or null to leave it alone. */
    private static function charsetReplacement($name, PDO $pdo = null) {
        if (!isset(self::CHARSET_ALIASES[$name])) {
            return null;
        }
        if ($pdo !== null && self::hasCharset($pdo, $name)) {
            return null; // this server knows it
        }
        return self::CHARSET_ALIASES[$name];
    }

    /** Replacement for a collation name, or null to leave it alone. */
    private static function collationReplacement($name, PDO $pdo = null) {
        // utf8mb3_* is MySQL 8 spelling of collations older servers call utf8_*.
        foreach (self::CHARSET_ALIASES as $from => $to) {
            if (strpos($name, $from . '_') === 0) {
                if ($pdo !== null && self::hasCollation($pdo, $name)) {
                    return null;
                }
                return $to . substr($name, strlen($from));
            }
        }
        if (!self::isUnportable($name)) {
            return null;
        }
        if ($pdo !== null && self::hasCollation($pdo, $name)) {
            return null;
        }
        $replacement = self::fallbackFor($name);
        return $replacement === $name ? null : $replacement;
    }

    /**
     * Parse "Unknown collation: 'x'" / "Unknown character set: 'x'" out of a
     * server error and rewrite that name. Returns null when the error is not a
     * charset problem or nothing could be changed, which tells the caller to
     * stop retrying and report the failure.
     */
    public static function rescue($sql, $errorMessage, array &$applied = []) {
        if (preg_match("/Unknown collation: '([^']+)'/i", $errorMessage, $m)) {
            $bad  = strtolower($m[1]);
            $good = self::fallbackFor($bad);
        } elseif (preg_match("/Unknown character set: '([^']+)'/i", $errorMessage, $m)) {
            $bad  = strtolower($m[1]);
            $good = $bad === 'utf8mb3' ? 'utf8' : self::SAFE_CHARSET;
        } else {
            return null;
        }

        if ($bad === $good) {
            return null; // even the fallback is unknown here — nothing left to try
        }
        $fixed = preg_replace('/\b' . preg_quote($bad, '/') . '\b/i', $good, $sql);
        if ($fixed === $sql || $fixed === null) {
            return null;
        }
        $applied[$bad] = $good;
        return $fixed;
    }

    // ── Statement classification ──────────────────────────────────────

    /**
     * True for statements that carry row data rather than schema.
     *
     * Collation rewriting must never run over these: an INSERT can legitimately
     * contain a collation name inside a text column, and rewriting it would
     * silently corrupt patient data.
     */
    public static function isDataStatement($sql) {
        return (bool)preg_match('/^\s*(?:\/\*.*?\*\/\s*)?(INSERT|REPLACE|LOAD\s+DATA)\b/is', $sql);
    }
}

/**
 * SqlCompatStream - line-buffered normalizer for the backup write path.
 *
 * mysqldump hands us the dump in 64 KB chunks, so a collation name can straddle
 * a chunk boundary. Rewriting per chunk would corrupt it. This buffers whole
 * lines instead: mysqldump (and BackupWriter) put every DDL statement on its own
 * line, so a line is always a complete unit for rewriting purposes.
 *
 * Lines carrying row data are streamed out byte-for-byte and never rewritten.
 */
class SqlCompatStream {

    /** A single line longer than this is a multi-row INSERT batch, never DDL. */
    const MAX_LINE = 262144;

    private $buffer          = '';
    private $rawUntilNewline = false;
    private $applied         = [];
    /** A multi-row INSERT spans many lines; its continuation lines are data too. */
    private $inDataStatement = false;
    private $atStatementStart = true;

    /** Feed a raw chunk, get back the chunk to write. Call flush() at the end. */
    public function feed($chunk) {
        $out = '';

        // Tail of an oversized data line already emitted raw — keep passing through.
        if ($this->rawUntilNewline) {
            $pos = strpos($chunk, "\n");
            if ($pos === false) {
                return $chunk;
            }
            $out  .= substr($chunk, 0, $pos + 1);
            $chunk = substr($chunk, $pos + 1);
            $this->rawUntilNewline = false;
        }

        $this->buffer .= $chunk;
        while (($pos = strpos($this->buffer, "\n")) !== false) {
            $line         = substr($this->buffer, 0, $pos + 1);
            $this->buffer = substr($this->buffer, $pos + 1);
            $out         .= $this->rewriteLine($line);
        }

        // No newline within MAX_LINE: a huge INSERT batch. Emit raw so memory stays flat.
        if (strlen($this->buffer) > self::MAX_LINE) {
            $out .= $this->buffer;
            $this->buffer          = '';
            $this->rawUntilNewline = true;
        }

        return $out;
    }

    /** Emit whatever is left in the buffer (dump files may not end with a newline). */
    public function flush() {
        $out          = $this->buffer === '' ? '' : $this->rewriteLine($this->buffer);
        $this->buffer = '';
        return $out;
    }

    private function rewriteLine($line) {
        if ($this->atStatementStart) {
            $this->inDataStatement = SqlCompat::isDataStatement($line);
        }
        // The PHP dump engine writes one row per line, so only the first line of
        // a multi-row INSERT starts with the keyword — track the statement, not the line.
        $this->atStatementStart = (substr(rtrim($line), -1) === ';');

        if ($this->inDataStatement || !SqlCompat::needsRewrite($line)) {
            return $line;
        }
        // No PDO: normalize unconditionally so the file imports on any server,
        // including through phpMyAdmin on a host we know nothing about.
        return SqlCompat::rewrite($line, null, $this->applied);
    }

    /** old => new collation pairs applied, for the backup log. */
    public function appliedFixes() {
        return $this->applied;
    }
}
