<?php
/**
 * RxProfile — a doctor's prescription sheet (ڕەچەتە), the left half of the
 * A5 landscape page printed by erp2/prescription-print.php.
 *
 * One row per doctor in doctor_rx_profiles. An empty field falls back to the
 * clinic (classes/Branding.php): the address, the first two phones, and the
 * clinic's own QR links. panel_mode 'image' prints an uploaded ready-made
 * design instead of the fields; when that file is missing the fields are used,
 * so a sheet never prints with a hole in it.
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Branding.php';

class RxProfile
{
    const UPLOAD_DIR = 'uploads/rx';
    const MAX_IMAGE  = 6 * 1024 * 1024;

    // The clinic's two pages, as on «lezan a5 v3.pdf».
    const DEFAULT_QR1     = 'https://lezan.zediamarketing.com/';
    const DEFAULT_QR2     = 'https://lezanclinics.carrd.co/';
    const DEFAULT_CAPTION = "سکان بکە\nو زیاتر\nبمانناسە";

    /** The fields the admin edits (admin/doctor-rx.php), with their limits. */
    const FIELDS = [
        'title'        => 60,
        'display_name' => 150,
        'credentials'  => 2000,
        'degrees'      => 300,
        'phones'       => 150,
        'address'      => 255,
        'qr1_url'      => 500,
        'qr2_url'      => 500,
        'qr_caption'   => 150,
    ];

    /**
     * The doctor's sheet, ready to print: every field filled (own value or the
     * clinic's), 'mode' resolved against the image actually being there, and
     * 'image_url' when it is.
     */
    public static function forUser(int $userId): array
    {
        $db   = Database::getInstance();
        $user = $db->fetch("SELECT id, full_name, phone FROM users WHERE id = :id", ['id' => $userId]) ?: [];
        $row  = $db->fetch("SELECT * FROM doctor_rx_profiles WHERE user_id = :id", ['id' => $userId]) ?: [];
        return self::resolve($row, $user);
    }

    /** Merge a stored (or posted, for the live preview) row with the defaults. */
    public static function resolve(array $row, array $user): array
    {
        $val = fn(string $k) => trim((string)($row[$k] ?? ''));
        $phones = Branding::phones();

        $p = [
            'user_id'      => (int)($user['id'] ?? $row['user_id'] ?? 0),
            'title'        => $val('title') !== '' ? $val('title') : 'دکتۆر',
            'display_name' => $val('display_name') !== '' ? $val('display_name') : self::plainName((string)($user['full_name'] ?? '')),
            'credentials'  => array_values(array_filter(array_map('trim', preg_split('/\R/u', $val('credentials'))), 'strlen')),
            'degrees'      => $val('degrees'),
            'phones'       => $val('phones') !== '' ? $val('phones') : implode(' - ', array_slice($phones, 0, 2)),
            'address'      => array_values(array_filter(array_map('trim', preg_split('/\R/u',
                                  $val('address') !== '' ? $val('address') : Branding::address())), 'strlen')),
            'qr1_url'      => $val('qr1_url') !== '' ? $val('qr1_url') : self::DEFAULT_QR1,
            'qr2_url'      => $val('qr2_url') !== '' ? $val('qr2_url') : self::DEFAULT_QR2,
            'qr_caption'   => array_values(array_filter(array_map('trim', preg_split('/\R/u',
                                  $val('qr_caption') !== '' ? $val('qr_caption') : self::DEFAULT_CAPTION)), 'strlen')),
            'panel_image'  => $val('panel_image'),
            'mode'         => 'fields',
            'image_url'    => null,
        ];
        if (($row['panel_mode'] ?? 'fields') === 'image' && self::imageExists($p['panel_image'])) {
            $p['mode']      = 'image';
            $p['image_url'] = APP_URL . '/' . $p['panel_image'] . '?v=' . filemtime(APP_ROOT . '/' . $p['panel_image']);
        }
        return $p;
    }

    /** The raw stored row (for the edit form), or the empty shape. */
    public static function stored(int $userId): array
    {
        $row = Database::getInstance()->fetch("SELECT * FROM doctor_rx_profiles WHERE user_id = :id", ['id' => $userId]);
        return $row ?: array_fill_keys(array_keys(self::FIELDS), '') + ['panel_mode' => 'fields', 'panel_image' => ''];
    }

    /**
     * Save the admin's form. $file is the uploaded panel image (or null);
     * $dropImage removes the current one. Returns the stored row.
     */
    public static function save(int $userId, array $in, ?array $file, bool $dropImage, int $actorId): array
    {
        $db = Database::getInstance();
        if (!$db->fetch("SELECT id FROM users WHERE id = :id AND role = 'doctor'", ['id' => $userId])) {
            throw new RuntimeException('دکتۆر نەدۆزرایەوە');
        }
        $old = self::stored($userId);

        $data = [];
        foreach (self::FIELDS as $k => $max) {
            $v = trim(str_replace("\r\n", "\n", (string)($in[$k] ?? '')));
            $data[$k] = $v === '' ? null : mb_substr($v, 0, $max);
        }
        foreach (['qr1_url', 'qr2_url'] as $k) {
            if ($data[$k] !== null && !preg_match('#^(https?://|www\.)\S+$#i', $data[$k])) {
                throw new RuntimeException('لینکی QR دەبێت بە http:// یان https:// دەست پێبکات');
            }
        }

        $image = (string)($old['panel_image'] ?? '');
        $newFile = null;
        if ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $newFile = self::storeImage($file, $userId);
        }
        if ($newFile !== null || $dropImage) {
            if ($image !== '' && self::isSafePath($image)) {
                @unlink(APP_ROOT . '/' . $image);
            }
            $image = $newFile ?? '';
        }
        $mode = ($in['panel_mode'] ?? 'fields') === 'image' ? 'image' : 'fields';
        if ($mode === 'image' && !self::imageExists($image)) {
            throw new RuntimeException('بۆ «وێنەی ئامادە» سەرەتا وێنەکە باربکە');
        }

        $data += [
            'panel_mode'  => $mode,
            'panel_image' => $image !== '' ? $image : null,
            'updated_at'  => date('Y-m-d H:i:s'),
            'updated_by'  => $actorId ?: null,
        ];
        if ($db->fetch("SELECT user_id FROM doctor_rx_profiles WHERE user_id = :id", ['id' => $userId])) {
            $db->update('doctor_rx_profiles', $data, 'user_id = :id', ['id' => $userId]);
        } else {
            $db->insert('doctor_rx_profiles', ['user_id' => $userId] + $data);
        }
        return self::stored($userId);
    }

    /**
     * The barcode printed on prescription $id: «77» and the id in 8 digits.
     * Digits only, on purpose — a scanner types through the keyboard layout,
     * and a Kurdish/Arabic layout would turn letters into other letters
     * (the till already turns ٣ into 3).
     */
    public static function barcode(int $id): string
    {
        return '77' . str_pad((string)$id, 8, '0', STR_PAD_LEFT);
    }

    /** The prescription id a scanned code stands for, or null. */
    public static function barcodeId(string $code): ?int
    {
        return preg_match('/^77(\d{8})$/', trim($code), $m) && (int)$m[1] > 0 ? (int)$m[1] : null;
    }

    /** «د.هێرش» and «د. هێرش» print as «هێرش» under the title «دکتۆر». */
    public static function plainName(string $name): string
    {
        return trim(preg_replace('/^\s*(د\s*\.|دکتۆر|Dr\.?)\s*/u', '', $name));
    }

    public static function isSafePath(string $path): bool
    {
        return strpos($path, '..') === false
            && preg_match('#^' . preg_quote(self::UPLOAD_DIR, '#') . '/[A-Za-z0-9_.-]+\.(png|jpe?g)$#', $path) === 1;
    }

    private static function imageExists(string $path): bool
    {
        return $path !== '' && self::isSafePath($path) && is_file(APP_ROOT . '/' . $path);
    }

    /** Validate (PNG/JPG by its bytes, ≤ 6 MB) and store an uploaded panel. */
    private static function storeImage(array $file, int $userId): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('بارکردنی وێنە سەرکەوتوو نەبوو');
        }
        if ($file['size'] > self::MAX_IMAGE) {
            throw new RuntimeException('قەبارەی وێنە دەبێت لە ٦ مێگابایت کەمتر بێت');
        }
        $info = @getimagesize($file['tmp_name']);
        $ext  = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg'][$info[2] ?? 0] ?? null;
        if ($ext === null) {
            throw new RuntimeException('تەنها وێنەی PNG یان JPG وەردەگیرێت');
        }
        $dir = APP_ROOT . '/' . self::UPLOAD_DIR;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('فۆڵدەری وێنە دروست نەکرا');
        }
        $rel = self::UPLOAD_DIR . '/panel_doctor_' . $userId . '_' . date('Ymd_His') . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], APP_ROOT . '/' . $rel)) {
            throw new RuntimeException('پاشەکەوتکردنی وێنە سەرکەوتوو نەبوو');
        }
        return $rel;
    }
}
