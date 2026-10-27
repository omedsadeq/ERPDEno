<?php
/**
 * HrDocument — an employee's documents (hr_employee_documents).
 *
 * The files sit in uploads/hr_documents/ under random 32-hex names. The folder
 * denies direct HTTP access (its .htaccess, written here), so a national card
 * or a contract is only ever handed out by hr/employee-doc.php, after the
 * hr.documents.view check. Images are shrunk in the browser before upload
 * (hr/_employee_docs.php), which keeps phone photos under the server's upload
 * limit; PDFs go up as they are.
 */

require_once __DIR__ . '/Database.php';

class HrDocument
{
    /** doc_type => [label, icon]. «photo» is also the picture on the profile. */
    const TYPES = [
        'photo'       => ['وێنەی کەسی',          'fa-user'],
        'national_id' => ['کارتی نیشتمانی',      'fa-id-card'],
        'info_card'   => ['کارتی زانیاری',       'fa-address-card'],
        'health_card' => ['کارتی تەندروستی',     'fa-heart-pulse'],
        'license'     => ['مۆڵەت',               'fa-certificate'],
        'contract'    => ['گرێبەست',             'fa-file-signature'],
        'certificate' => ['بڕوانامە',            'fa-graduation-cap'],
        'passport'    => ['پاسپۆرت',             'fa-passport'],
        'other'       => ['ئەوانی تر',           'fa-file'],
    ];

    /** What may be uploaded: detected type => stored extension. */
    const MIME = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'application/pdf' => 'pdf',
    ];

    const DIR = 'uploads/hr_documents';
    const MAX_BYTES = 10 * 1024 * 1024;
    const EXPIRY_WARN_DAYS = 30;

    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public static function typeLabel(string $type): string
    {
        return self::TYPES[$type][0] ?? self::TYPES['other'][0];
    }

    public static function typeIcon(string $type): string
    {
        return self::TYPES[$type][1] ?? self::TYPES['other'][1];
    }

    /** The folder, created with its deny-all .htaccess on first use. */
    public static function dir(): string
    {
        $dir = APP_ROOT . '/' . self::DIR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "# Served only through hr/employee-doc.php (login + hr.documents.view).\n"
                . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
        }
        return $dir;
    }

    /** Absolute path of a stored file, or null when it is missing. */
    public static function path(array $doc): ?string
    {
        $name = basename((string)$doc['file_name']);
        $path = APP_ROOT . '/' . self::DIR . '/' . $name;
        return $name !== '' && is_file($path) ? $path : null;
    }

    public static function isImage(array $doc): bool
    {
        return strpos((string)$doc['mime_type'], 'image/') === 0;
    }

    /** 'expired' | 'soon' | null, from expiry_date. */
    public static function expiryState(array $doc): ?string
    {
        if (empty($doc['expiry_date'])) {
            return null;
        }
        $days = (int)floor((strtotime($doc['expiry_date']) - strtotime(date('Y-m-d'))) / 86400);
        if ($days < 0) {
            return 'expired';
        }
        return $days <= self::EXPIRY_WARN_DAYS ? 'soon' : null;
    }

    /** A php.ini size («2M», «8M», «512K») in bytes; 0 = no limit. */
    private static function iniBytes(string $key): int
    {
        $v = trim((string)ini_get($key));
        $n = (float)$v;
        switch (strtolower(substr($v, -1))) {
            case 'g': $n *= 1024;
            // no break
            case 'm': $n *= 1024;
            // no break
            case 'k': $n *= 1024;
        }
        return (int)$n;
    }

    /** The server's real ceiling for one file in bytes (php.ini), capped at MAX_BYTES. */
    public static function uploadLimit(): int
    {
        $limits = array_filter([self::iniBytes('upload_max_filesize'), self::iniBytes('post_max_size')]);
        return (int)min(array_merge($limits, [self::MAX_BYTES]));
    }

    /**
     * Bytes one upload request may carry in all (post_max_size, less room for
     * the form fields). Over it PHP drops the request before any code runs, so
     * the browser checks this before sending.
     */
    public static function requestLimit(): int
    {
        $post = self::iniBytes('post_max_size') ?: self::MAX_BYTES * 4;
        return (int)($post * 0.9);
    }

    public function find(int $id): ?array
    {
        $row = $this->db->fetch('SELECT * FROM hr_employee_documents WHERE id = ?', [$id]);
        return $row ?: null;
    }

    /** An employee's documents, in the order of TYPES, newest first within a type. */
    public function forEmployee(int $employeeId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT d.*, u.full_name AS uploaded_by_name
             FROM hr_employee_documents d LEFT JOIN users u ON u.id = d.uploaded_by
             WHERE d.employee_id = ? ORDER BY d.created_at DESC, d.id DESC',
            [$employeeId]
        );
        $order = array_flip(array_keys(self::TYPES));
        usort($rows, function ($a, $b) use ($order) {
            return ($order[$a['doc_type']] ?? 99) <=> ($order[$b['doc_type']] ?? 99);
        });
        return $rows;
    }

    /** employee_id => newest photo document id, for the avatars on the list. */
    public function photoIds(): array
    {
        $out = [];
        foreach ($this->db->fetchAll(
            "SELECT employee_id, MAX(id) AS id FROM hr_employee_documents WHERE doc_type = 'photo' GROUP BY employee_id"
        ) as $r) {
            $out[(int)$r['employee_id']] = (int)$r['id'];
        }
        return $out;
    }

    /** employee_id => [documents, expired, expiring soon], for the list. */
    public function counts(): array
    {
        $out = [];
        $soon = date('Y-m-d', strtotime('+' . self::EXPIRY_WARN_DAYS . ' days'));
        foreach ($this->db->fetchAll(
            'SELECT employee_id, COUNT(*) AS n,
                    SUM(expiry_date IS NOT NULL AND expiry_date < CURDATE()) AS expired,
                    SUM(expiry_date IS NOT NULL AND expiry_date >= CURDATE() AND expiry_date <= ?) AS soon
             FROM hr_employee_documents GROUP BY employee_id',
            [$soon]
        ) as $r) {
            $out[(int)$r['employee_id']] = ['n' => (int)$r['n'], 'expired' => (int)$r['expired'], 'soon' => (int)$r['soon']];
        }
        return $out;
    }

    /**
     * Save one uploaded file for $employeeId. Throws InvalidArgumentException
     * with a Kurdish message. Returns the new document id.
     */
    public function store(int $employeeId, string $type, array $file, ?string $title, ?string $expiry, int $userId): int
    {
        if (!isset(self::TYPES[$type])) {
            throw new InvalidArgumentException('جۆری بەڵگەنامە نەناسراوە');
        }
        if (!$this->db->fetch('SELECT id FROM hr_employees WHERE id = ?', [$employeeId])) {
            throw new InvalidArgumentException('کارمەند نەدۆزرایەوە');
        }
        $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            throw new InvalidArgumentException('فایلەکە زۆر گەورەیە (زۆرترین: ' . self::formatSize(self::uploadLimit()) . ')');
        }
        if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
            throw new InvalidArgumentException('فایلێک هەڵبژێرە');
        }
        $size = (int)filesize($file['tmp_name']);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw new InvalidArgumentException('فایلەکە زۆر گەورەیە (زۆرترین: ' . self::formatSize(self::uploadLimit()) . ')');
        }
        // The type comes from the bytes, never from the name or the browser.
        $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!isset(self::MIME[$mime])) {
            throw new InvalidArgumentException('تەنها وێنە (JPG، PNG، WEBP) یان PDF وەردەگیرێت');
        }
        if (strpos($mime, 'image/') === 0 && !@getimagesize($file['tmp_name'])) {
            throw new InvalidArgumentException('وێنەکە ناخوێندرێتەوە');
        }

        $name = bin2hex(random_bytes(16)) . '.' . self::MIME[$mime];
        if (!move_uploaded_file($file['tmp_name'], self::dir() . '/' . $name)) {
            throw new RuntimeException('پاشەکەوتکردنی فایل سەرکەوتوو نەبوو');
        }
        $row = [
            'employee_id'   => $employeeId,
            'doc_type'      => $type,
            'title'         => ($t = trim((string)$title)) === '' ? null : mb_substr($t, 0, 150),
            'expiry_date'   => $expiry,
            'file_name'     => $name,
            'original_name' => mb_substr(basename((string)($file['name'] ?? '')), 0, 255) ?: null,
            'mime_type'     => $mime,
            'file_size'     => $size,
            'uploaded_by'   => $userId ?: null,
        ];
        try {
            $id = (int)$this->db->insert('hr_employee_documents', $row);
        } catch (Throwable $ex) {
            @unlink(self::dir() . '/' . $name);
            throw $ex;
        }
        if (class_exists('AuditLog')) {
            AuditLog::log('hr_document_upload', 'hr_employee_documents', $id, null,
                ['employee_id' => $employeeId, 'doc_type' => $type, 'original_name' => $row['original_name']]);
        }
        return $id;
    }

    public function delete(int $id): ?array
    {
        $doc = $this->find($id);
        if (!$doc) {
            return null;
        }
        $this->db->delete('hr_employee_documents', 'id = ?', [$id]);
        if ($path = self::path($doc)) {
            @unlink($path);
        }
        if (class_exists('AuditLog')) {
            AuditLog::log('hr_document_delete', 'hr_employee_documents', $id,
                ['employee_id' => (int)$doc['employee_id'], 'doc_type' => $doc['doc_type'], 'original_name' => $doc['original_name']], null);
        }
        return $doc;
    }

    /** Remove every file of an employee who is being deleted (rows go by FK cascade). */
    public function deleteFilesOf(int $employeeId): void
    {
        foreach ($this->db->fetchAll('SELECT file_name FROM hr_employee_documents WHERE employee_id = ?', [$employeeId]) as $doc) {
            if ($path = self::path($doc)) {
                @unlink($path);
            }
        }
    }

    public static function formatSize(int $bytes): string
    {
        return $bytes >= 1048576
            ? rtrim(rtrim(number_format($bytes / 1048576, 1), '0'), '.') . ' MB'
            : max(1, (int)round($bytes / 1024)) . ' KB';
    }
}
