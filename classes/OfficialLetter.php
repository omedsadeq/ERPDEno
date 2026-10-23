<?php
/**
 * OfficialLetter — نوسراوی فەرمی (outgoing official letters).
 *
 * Three things live here:
 *   - the letters themselves (official_letters) and their numbering
 *   - reusable templates (official_letter_templates) that prefill the form
 *   - the letterhead: letter_* keys in `settings` plus the uploaded images
 *     (logo / header / footer / signature / seal) in uploads/letters/
 *
 * Drawing the A4 sheet is letters/_render.php's job; it takes the arrays this
 * class returns and never touches the database.
 *
 * Numbering: letter_number_format is a pattern such as «{n}» or «{n}/{y}».
 * seq_no keeps the {n} part so the next number is MAX(seq_no) + 1 — per year
 * of the letter date when letter_number_yearly is on. A number typed by hand
 * is parsed back through the same pattern, so the sequence carries on from it.
 */

require_once __DIR__ . '/Database.php';

class OfficialLetter
{
    /** Uploaded images, keyed by kind → the setting holding the file name. */
    const ASSETS = [
        'logo'      => 'letter_logo',
        'header'    => 'letter_header_image',
        'footer'    => 'letter_footer_image',
        'signature' => 'letter_signature_image',
        'seal'      => 'letter_seal_image',
    ];

    /** Columns a letter (and, minus the first ones, a template) is made of. */
    const TEXT_FIELDS = [
        'recipient'      => 255,
        'subject'        => 255,
        'reference_text' => 255,
        'greeting'       => 255,
        'body'           => 60000,
        'closing'        => 255,
        'signer_name'    => 150,
        'signer_title'   => 255,
        'cc_list'        => 5000,
        'attachments'    => 5000,
    ];

    const ASSET_DIR = 'uploads/letters';

    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ── Letterhead settings ───────────────────────────────────────────

    /**
     * Every letter_* setting with its default. Header and footer text default
     * to the clinic details the receipts already print (includes/
     * clinic_branding.php), so a fresh install has a usable letterhead.
     */
    public function defaults(): array
    {
        $db = $this->db;
        require __DIR__ . '/../includes/clinic_branding.php';

        return [
            'letter_header_mode'          => 'text',   // text | image
            'letter_header_right'         => $clinicName,
            'letter_header_center'        => '',
            'letter_header_left'          => $clinicNameEn,
            'letter_show_logo'            => '1',
            'letter_header_line'          => '1',
            'letter_footer_mode'          => 'text',   // text | image | none
            'letter_footer_text'          => $clinicAddress . "\n" . implode('  -  ', $clinicPhones),
            'letter_number_format'        => '{n}',
            'letter_number_yearly'        => '1',
            'letter_digits'               => 'ku',     // ku = ١٢٣ | latin = 123
            'letter_kurdish_year'         => '1',
            'letter_default_greeting'     => 'سڵاو و ڕێز...',
            'letter_default_closing'      => 'لەگەڵ ڕێز و پێزانینماندا...',
            'letter_default_signer_name'  => '',
            'letter_default_signer_title' => '',
            'letter_signature_side'       => 'left',   // left | center | right
            'letter_accent_color'         => '#1f3b63',
            'letter_font_size'            => '16',
            'letter_logo'                 => '',
            'letter_header_image'         => '',
            'letter_footer_image'         => '',
            'letter_signature_image'      => '',
            'letter_seal_image'           => '',
        ];
    }

    /** Saved letter_* settings over the defaults. A key saved empty stays empty. */
    public function settings(): array
    {
        $cfg = $this->defaults();
        $rows = $this->db->fetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'letter\\_%'");
        foreach ($rows as $row) {
            if (array_key_exists($row['setting_key'], $cfg)) {
                $cfg[$row['setting_key']] = (string)$row['setting_value'];
            }
        }
        return self::sanitizeSettings($cfg);
    }

    /**
     * Clamp every setting to what the renderer understands. Used on the saved
     * values and on the unsaved ones the settings page sends for its preview.
     */
    public static function sanitizeSettings(array $cfg): array
    {
        $pick = function ($value, array $allowed, $fallback) {
            return in_array($value, $allowed, true) ? $value : $fallback;
        };
        $cfg['letter_header_mode']    = $pick($cfg['letter_header_mode'] ?? '', ['text', 'image'], 'text');
        $cfg['letter_footer_mode']    = $pick($cfg['letter_footer_mode'] ?? '', ['text', 'image', 'none'], 'text');
        $cfg['letter_digits']         = $pick($cfg['letter_digits'] ?? '', ['ku', 'latin'], 'ku');
        $cfg['letter_signature_side'] = $pick($cfg['letter_signature_side'] ?? '', ['left', 'center', 'right'], 'left');
        foreach (['letter_show_logo', 'letter_header_line', 'letter_number_yearly', 'letter_kurdish_year'] as $flag) {
            $cfg[$flag] = !empty($cfg[$flag]) && $cfg[$flag] !== '0' ? '1' : '0';
        }
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string)($cfg['letter_accent_color'] ?? ''))) {
            $cfg['letter_accent_color'] = '#1f3b63';
        }
        $size = (int)($cfg['letter_font_size'] ?? 16);
        $cfg['letter_font_size'] = (string)max(12, min(22, $size ?: 16));

        $format = trim((string)($cfg['letter_number_format'] ?? ''));
        if ($format === '' || strpos($format, '{n}') === false) {
            $format = '{n}';
        }
        $cfg['letter_number_format'] = mb_substr($format, 0, 40);
        return $cfg;
    }

    /** Upsert letter_* keys. Unknown keys are ignored. */
    public function saveSettings(array $values): void
    {
        $known = $this->defaults();
        foreach ($values as $key => $value) {
            if (!array_key_exists($key, $known)) {
                continue;
            }
            $this->db->query(
                "INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
                ['k' => $key, 'v' => (string)$value]
            );
        }
        if (class_exists('Settings')) {
            Settings::invalidate();
        }
    }

    // ── Numbering ─────────────────────────────────────────────────────

    /** ١٢٣ / ۱۲۳ typed on a Kurdish keyboard → 123. */
    public static function normalizeDigits(string $s): string
    {
        return strtr($s, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
    }

    public static function formatNumber(string $format, int $seq, string $date): string
    {
        $ts = strtotime($date) ?: time();
        return strtr($format, [
            '{n}' => (string)$seq,
            '{y}' => date('Y', $ts),
            '{m}' => date('n', $ts),
        ]);
    }

    /** The {n} part of a number written in $format, or 0 when it does not fit. */
    public static function parseSeq(string $format, string $number): int
    {
        $number = trim(self::normalizeDigits($number));
        $regex = preg_quote($format, '/');
        $regex = strtr($regex, [
            '\{n\}' => '(?P<n>\d{1,9})',
            '\{y\}' => '\d{4}',
            '\{m\}' => '\d{1,2}',
        ]);
        if (preg_match('/^' . $regex . '$/u', $number, $m)) {
            return (int)$m['n'];
        }
        // A bare number always counts, whatever the pattern.
        return ctype_digit($number) && strlen($number) <= 9 ? (int)$number : 0;
    }

    private function nextSeq(int $year, array $cfg, bool $lock = false): int
    {
        $sql = $cfg['letter_number_yearly'] === '1'
            ? "SELECT COALESCE(MAX(seq_no), 0) AS m FROM official_letters WHERE seq_year = :y"
            : "SELECT COALESCE(MAX(seq_no), 0) AS m FROM official_letters";
        $params = $cfg['letter_number_yearly'] === '1' ? ['y' => $year] : [];
        if ($lock) {
            $sql .= " FOR UPDATE";
        }
        return (int)($this->db->fetch($sql, $params)['m'] ?? 0) + 1;
    }

    /** The number the next letter dated $date will get (shown on the form). */
    public function suggestNumber(string $date, array $cfg): string
    {
        $year = (int)date('Y', strtotime($date) ?: time());
        return self::formatNumber($cfg['letter_number_format'], $this->nextSeq($year, $cfg), $date);
    }

    /** Is $number already used by another letter (in the same year when numbering is yearly)? */
    public function numberTaken(string $number, string $date, array $cfg, int $exceptId = 0): bool
    {
        $sql = "SELECT id FROM official_letters WHERE letter_number = :n AND id <> :x";
        $params = ['n' => $number, 'x' => $exceptId];
        if ($cfg['letter_number_yearly'] === '1') {
            $sql .= " AND seq_year = :y";
            $params['y'] = (int)date('Y', strtotime($date));
        }
        return (bool)$this->db->fetch($sql . " LIMIT 1", $params);
    }

    // ── Input ─────────────────────────────────────────────────────────

    /**
     * Clean a submitted letter/template. Throws InvalidArgumentException with
     * a message meant for the user when something required is missing.
     */
    public static function fromInput(array $in, bool $isTemplate = false): array
    {
        $out = [];
        foreach (self::TEXT_FIELDS as $field => $max) {
            $value = str_replace(["\r\n", "\r"], "\n", (string)($in[$field] ?? ''));
            $value = trim($value);
            if ($field !== 'body' && $field !== 'cc_list' && $field !== 'attachments' && $field !== 'signer_title') {
                $value = preg_replace('/\s*\n\s*/', ' ', $value);
            }
            $out[$field] = mb_substr($value, 0, $max);
        }

        if ($isTemplate) {
            $out['name'] = mb_substr(trim((string)($in['name'] ?? '')), 0, 150);
            if ($out['name'] === '') {
                throw new InvalidArgumentException('ناوی قاڵب پێویستە');
            }
            return $out;
        }

        $date = trim(self::normalizeDigits((string)($in['letter_date'] ?? '')));
        $d = DateTime::createFromFormat('!Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('ڕێکەوتی نوسراو دروست نییە');
        }
        $out['letter_date'] = $date;
        $out['letter_number'] = mb_substr(trim(self::normalizeDigits((string)($in['letter_number'] ?? ''))), 0, 60);
        $out['show_signature'] = !empty($in['show_signature']) ? 1 : 0;
        $out['show_seal'] = !empty($in['show_seal']) ? 1 : 0;
        $tpl = (int)($in['template_id'] ?? 0);
        $out['template_id'] = $tpl > 0 ? $tpl : null;

        if ($out['template_id'] !== null
            && !Database::getInstance()->fetch("SELECT id FROM official_letter_templates WHERE id = :id", ['id' => $out['template_id']])) {
            $out['template_id'] = null;   // deleted while the letter was being written
        }
        if ($out['recipient'] === '') {
            throw new InvalidArgumentException('خانەی «بۆ» (لایەنی پەیوەندیدار) پێویستە');
        }
        if ($out['subject'] === '') {
            throw new InvalidArgumentException('بابەتی نوسراو پێویستە');
        }
        if ($out['body'] === '') {
            throw new InvalidArgumentException('دەقی نوسراو پێویستە');
        }
        return $out;
    }

    // ── Letters ───────────────────────────────────────────────────────

    public function find(int $id): ?array
    {
        $row = $this->db->fetch(
            "SELECT l.*, cu.full_name AS created_by_name, uu.full_name AS updated_by_name,
                    xu.full_name AS cancelled_by_name
             FROM official_letters l
             LEFT JOIN users cu ON cu.id = l.created_by
             LEFT JOIN users uu ON uu.id = l.updated_by
             LEFT JOIN users xu ON xu.id = l.cancelled_by
             WHERE l.id = :id",
            ['id' => $id]
        );
        return $row ?: null;
    }

    /**
     * Save a new letter. With $autoNumber the number is allocated here, inside
     * the transaction, so two people saving at the same moment cannot both get
     * the number the form suggested — the second one gets the next.
     */
    public function create(array $data, array $cfg, int $userId, bool $autoNumber): int
    {
        $year = (int)date('Y', strtotime($data['letter_date']));
        $nullable = ['reference_text', 'greeting', 'closing', 'signer_name', 'signer_title', 'cc_list', 'attachments'];
        foreach ($nullable as $f) {
            if (($data[$f] ?? '') === '') {
                $data[$f] = null;
            }
        }

        if (!$autoNumber) {
            if ($data['letter_number'] === '') {
                throw new InvalidArgumentException('ژمارەی نوسراو پێویستە');
            }
            if ($this->numberTaken($data['letter_number'], $data['letter_date'], $cfg)) {
                throw new InvalidArgumentException('ئەم ژمارەیە پێشتر بۆ نوسراوێکی تر بەکارهاتووە');
            }
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->db->beginTransaction();
            try {
                if ($autoNumber) {
                    $seq = $this->nextSeq($year, $cfg, true);
                    $data['letter_number'] = self::formatNumber($cfg['letter_number_format'], $seq, $data['letter_date']);
                } else {
                    $seq = self::parseSeq($cfg['letter_number_format'], $data['letter_number']);
                }
                $row = $data;
                $row['seq_year'] = $year;
                $row['seq_no'] = $seq;
                $row['status'] = 'active';
                $row['created_by'] = $userId ?: null;
                $id = (int)$this->db->insert('official_letters', $row);
                $this->db->commit();
                if (class_exists('AuditLog')) {
                    AuditLog::log('letter_create', 'official_letters', $id, null, [
                        'letter_number' => $row['letter_number'],
                        'subject'       => $row['subject'],
                        'recipient'     => $row['recipient'],
                    ]);
                }
                return $id;
            } catch (PDOException $e) {
                $this->db->rollback();
                $duplicate = (int)($e->errorInfo[1] ?? 0) === 1062;
                if ($duplicate && $autoNumber) {
                    continue;   // someone took that number a moment ago — take the next
                }
                if ($duplicate) {
                    throw new InvalidArgumentException('ئەم ژمارەیە پێشتر بۆ نوسراوێکی تر بەکارهاتووە');
                }
                throw $e;
            }
        }
        throw new RuntimeException('ژمارەی نوسراو دابین نەکرا، تکایە دووبارە هەوڵ بدەرەوە');
    }

    public function update(int $id, array $data, array $cfg, int $userId): void
    {
        $old = $this->find($id);
        if (!$old) {
            throw new InvalidArgumentException('نوسراو نەدۆزرایەوە');
        }
        if ($data['letter_number'] === '') {
            throw new InvalidArgumentException('ژمارەی نوسراو پێویستە');
        }
        if ($this->numberTaken($data['letter_number'], $data['letter_date'], $cfg, $id)) {
            throw new InvalidArgumentException('ئەم ژمارەیە پێشتر بۆ نوسراوێکی تر بەکارهاتووە');
        }
        foreach (['reference_text', 'greeting', 'closing', 'signer_name', 'signer_title', 'cc_list', 'attachments'] as $f) {
            if (($data[$f] ?? '') === '') {
                $data[$f] = null;
            }
        }
        $data['seq_year'] = (int)date('Y', strtotime($data['letter_date']));
        $data['seq_no'] = self::parseSeq($cfg['letter_number_format'], $data['letter_number']);
        $data['updated_by'] = $userId ?: null;
        $data['updated_at'] = date('Y-m-d H:i:s');
        try {
            $this->db->update('official_letters', $data, 'id = :id', ['id' => $id]);
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                throw new InvalidArgumentException('ئەم ژمارەیە پێشتر بۆ نوسراوێکی تر بەکارهاتووە');
            }
            throw $e;
        }
        if (class_exists('AuditLog')) {
            AuditLog::logChange('letter_update', 'official_letters', $id, $old, $data);
        }
    }

    public function cancel(int $id, string $reason, int $userId): void
    {
        $this->db->update('official_letters', [
            'status'        => 'cancelled',
            'cancel_reason' => mb_substr(trim($reason), 0, 255) ?: null,
            'cancelled_at'  => date('Y-m-d H:i:s'),
            'cancelled_by'  => $userId ?: null,
        ], "id = :id AND status = 'active'", ['id' => $id]);
        if (class_exists('AuditLog')) {
            AuditLog::log('letter_cancel', 'official_letters', $id, null, ['reason' => $reason]);
        }
    }

    public function restore(int $id, int $userId): void
    {
        $this->db->update('official_letters', [
            'status'        => 'active',
            'cancel_reason' => null,
            'cancelled_at'  => null,
            'cancelled_by'  => null,
            'updated_by'    => $userId ?: null,
            'updated_at'    => date('Y-m-d H:i:s'),
        ], "id = :id AND status = 'cancelled'", ['id' => $id]);
        if (class_exists('AuditLog')) {
            AuditLog::log('letter_restore', 'official_letters', $id);
        }
    }

    public function delete(int $id): void
    {
        $old = $this->find($id);
        $this->db->delete('official_letters', 'id = :id', ['id' => $id]);
        if ($old && class_exists('AuditLog')) {
            AuditLog::log('letter_delete', 'official_letters', $id, [
                'letter_number' => $old['letter_number'],
                'subject'       => $old['subject'],
                'recipient'     => $old['recipient'],
            ]);
        }
    }

    /**
     * The archive. Filters: q (number / recipient / subject / text / signer),
     * from, to (Y-m-d), year, status (active|cancelled).
     *
     * @return array{0: array, 1: int} rows of the page, total matching
     */
    public function search(array $f, int $page, int $perPage): array
    {
        $where = ['1=1'];
        $params = [];
        $q = trim(self::normalizeDigits((string)($f['q'] ?? '')));
        if ($q !== '') {
            $like = '%' . $q . '%';
            $where[] = "(l.letter_number = :qn OR l.letter_number LIKE :q1 OR l.recipient LIKE :q2
                         OR l.subject LIKE :q3 OR l.body LIKE :q4 OR COALESCE(l.signer_name, '') LIKE :q5
                         OR COALESCE(l.cc_list, '') LIKE :q6)";
            $params += ['qn' => $q, 'q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like, 'q5' => $like, 'q6' => $like];
        }
        foreach (['from' => '>=', 'to' => '<='] as $key => $op) {
            $v = (string)($f[$key] ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                $where[] = "l.letter_date {$op} :{$key}";
                $params[$key] = $v;
            }
        }
        if (!empty($f['year']) && (int)$f['year'] > 1900) {
            $where[] = "l.seq_year = :year";
            $params['year'] = (int)$f['year'];
        }
        if (in_array($f['status'] ?? '', ['active', 'cancelled'], true)) {
            $where[] = "l.status = :status";
            $params['status'] = $f['status'];
        }
        $whereSql = implode(' AND ', $where);

        $total = (int)($this->db->fetch("SELECT COUNT(*) AS c FROM official_letters l WHERE {$whereSql}", $params)['c'] ?? 0);
        $offset = max(0, ($page - 1) * $perPage);
        $rows = $this->db->fetchAll(
            "SELECT l.id, l.letter_number, l.letter_date, l.recipient, l.subject, l.signer_name,
                    l.status, l.cancel_reason, l.created_at, cu.full_name AS created_by_name
             FROM official_letters l
             LEFT JOIN users cu ON cu.id = l.created_by
             WHERE {$whereSql}
             ORDER BY l.letter_date DESC, l.seq_year DESC, l.seq_no DESC, l.id DESC
             LIMIT " . (int)$perPage . " OFFSET " . (int)$offset,
            $params
        );
        return [$rows, $total];
    }

    public function stats(): array
    {
        $row = $this->db->fetch(
            "SELECT COUNT(*) AS total,
                    SUM(seq_year = YEAR(CURDATE())) AS this_year,
                    SUM(letter_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS this_month,
                    SUM(status = 'cancelled') AS cancelled
             FROM official_letters"
        );
        return array_map('intval', $row ?: []);
    }

    public function years(): array
    {
        return array_map('intval', array_column(
            $this->db->fetchAll("SELECT DISTINCT seq_year FROM official_letters ORDER BY seq_year DESC"),
            'seq_year'
        ));
    }

    /** Earlier values of a column, most used first — the form's autocomplete. */
    public function suggestions(string $column, int $limit = 150): array
    {
        if (!in_array($column, ['recipient', 'signer_name', 'signer_title', 'subject'], true)) {
            return [];
        }
        return array_column($this->db->fetchAll(
            "SELECT {$column} AS v, COUNT(*) AS c FROM official_letters
             WHERE {$column} IS NOT NULL AND {$column} <> ''
             GROUP BY {$column} ORDER BY c DESC, MAX(id) DESC LIMIT " . (int)$limit
        ), 'v');
    }

    // ── Templates ─────────────────────────────────────────────────────

    public function templates(): array
    {
        return $this->db->fetchAll(
            "SELECT t.*, u.full_name AS created_by_name,
                    (SELECT COUNT(*) FROM official_letters l WHERE l.template_id = t.id) AS use_count
             FROM official_letter_templates t
             LEFT JOIN users u ON u.id = t.created_by
             ORDER BY t.sort_order, t.name"
        );
    }

    public function findTemplate(int $id): ?array
    {
        $row = $this->db->fetch("SELECT * FROM official_letter_templates WHERE id = :id", ['id' => $id]);
        return $row ?: null;
    }

    public function saveTemplate(array $data, int $userId, int $id = 0): int
    {
        $row = ['name' => $data['name']];
        foreach (array_keys(self::TEXT_FIELDS) as $f) {
            $row[$f] = ($data[$f] ?? '') === '' ? null : $data[$f];
        }
        if ($id > 0) {
            $row['updated_at'] = date('Y-m-d H:i:s');
            $this->db->update('official_letter_templates', $row, 'id = :id', ['id' => $id]);
            return $id;
        }
        $row['created_by'] = $userId ?: null;
        $row['sort_order'] = (int)($this->db->fetch("SELECT COALESCE(MAX(sort_order), 0) + 10 AS s FROM official_letter_templates")['s'] ?? 0);
        return (int)$this->db->insert('official_letter_templates', $row);
    }

    public function deleteTemplate(int $id): void
    {
        $this->db->delete('official_letter_templates', 'id = :id', ['id' => $id]);
    }

    // ── Letterhead images ─────────────────────────────────────────────
    // Kept out of the public web root's reach: the folder denies direct HTTP
    // access and letters/asset.php hands the images out to logged-in users
    // who may see letters. A signature and a seal must not be downloadable
    // by anyone who guesses a URL.

    public static function assetDir(): string
    {
        $dir = APP_ROOT . '/' . self::ASSET_DIR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $htaccess = $dir . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "# Served only through letters/asset.php (login + permission).\n"
                . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
        }
        return $dir;
    }

    /** Absolute path of an uploaded image, or null. The logo falls back to the clinic logo. */
    public static function assetPath(string $kind, array $cfg): ?string
    {
        if (!isset(self::ASSETS[$kind])) {
            return null;
        }
        $file = basename((string)($cfg[self::ASSETS[$kind]] ?? ''));
        if ($file !== '' && is_file(APP_ROOT . '/' . self::ASSET_DIR . '/' . $file)) {
            return APP_ROOT . '/' . self::ASSET_DIR . '/' . $file;
        }
        // No letter-specific logo: the centre's logo (admin/settings.php).
        if ($kind === 'logo' && is_file(Branding::logoPath())) {
            return Branding::logoPath();
        }
        return null;
    }

    public static function hasUpload(string $kind, array $cfg): bool
    {
        $file = basename((string)($cfg[self::ASSETS[$kind] ?? ''] ?? ''));
        return $file !== '' && is_file(APP_ROOT . '/' . self::ASSET_DIR . '/' . $file);
    }

    /** Browser URL of each image the letterhead may draw (null = none). */
    public static function assetUrls(array $cfg): array
    {
        $urls = [];
        foreach (array_keys(self::ASSETS) as $kind) {
            $path = self::assetPath($kind, $cfg);
            if ($path === null) {
                $urls[$kind] = null;
            } elseif ($kind === 'logo' && !self::hasUpload('logo', $cfg)) {
                $urls[$kind] = Branding::logoUrl();
            } else {
                $urls[$kind] = APP_URL . '/letters/asset.php?k=' . $kind . '&v=' . (int)@filemtime($path);
            }
        }
        return $urls;
    }

    /** File paths of the same images — what mPDF embeds. */
    public static function assetPaths(array $cfg): array
    {
        $paths = [];
        foreach (array_keys(self::ASSETS) as $kind) {
            $paths[$kind] = self::assetPath($kind, $cfg);
        }
        return $paths;
    }

    /**
     * Store an uploaded image for $kind and return its new file name (to be
     * saved into the setting). The previous file is left alone: the caller
     * removes it once the new name is saved (removeAssetFile).
     */
    public function storeAsset(string $kind, array $file): string
    {
        if (!isset(self::ASSETS[$kind])) {
            throw new InvalidArgumentException('جۆری وێنە نەناسراوە');
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
            throw new InvalidArgumentException('بارکردنی وێنە سەرکەوتوو نەبوو');
        }
        if (($file['size'] ?? 0) > 3 * 1024 * 1024) {
            throw new InvalidArgumentException('قەبارەی وێنە دەبێت لە ٣ مێگابایت کەمتر بێت');
        }
        $info = @getimagesize($file['tmp_name']);
        $types = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg'];
        if (!$info || !isset($types[$info[2]])) {
            throw new InvalidArgumentException('تەنها وێنەی PNG یان JPG وەردەگیرێت');
        }
        $name = $kind . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $types[$info[2]];
        $dir = self::assetDir();
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
            throw new RuntimeException('پاشەکەوتکردنی وێنە سەرکەوتوو نەبوو');
        }
        return $name;
    }

    /** Delete the file of $kind named in $cfg from disk. */
    public function removeAssetFile(string $kind, array $cfg): void
    {
        $old = basename((string)($cfg[self::ASSETS[$kind] ?? ''] ?? ''));
        if ($old !== '' && is_file(APP_ROOT . '/' . self::ASSET_DIR . '/' . $old)) {
            @unlink(APP_ROOT . '/' . self::ASSET_DIR . '/' . $old);
        }
    }
}
