<?php
/**
 * RxProfile — one doctor's part of the prescription sheet (ڕەچەتە), the left
 * half of the A5 landscape page printed by erp2/prescription-print.php.
 *
 * One row per prescriber (a doctor, or an admin who writes prescriptions) in
 * doctor_rx_profiles. An empty field falls back to the clinic's template
 * (classes/RxTemplate.php: title, address, phones, QR links, caption).
 * panel_mode 'image' prints an uploaded ready-made design instead of the
 * fields; when that file is missing the fields are used, so a sheet never
 * prints with a hole in it.
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/RxTemplate.php';

class RxProfile
{
    const UPLOAD_DIR = 'uploads/rx';

    // the template's originals, for the edit form's placeholders
    const DEFAULT_QR1     = RxTemplate::DEFAULT_QR1;
    const DEFAULT_QR2     = RxTemplate::DEFAULT_QR2;
    const DEFAULT_CAPTION = RxTemplate::DEFAULT_CAPTION;

    /** Who may have a sheet: whoever writes prescriptions. */
    const ROLES = ['doctor', 'admin'];

    /** The fields edited on admin/doctor-rx.php, with their limits. */
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
     * template's), 'mode' resolved against the image actually being there, and
     * 'image_url' when it is.
     */
    public static function forUser(int $userId): array
    {
        $db   = Database::getInstance();
        $user = $db->fetch("SELECT id, full_name, phone FROM users WHERE id = :id", ['id' => $userId]) ?: [];
        $row  = $db->fetch("SELECT * FROM doctor_rx_profiles WHERE user_id = :id", ['id' => $userId]) ?: [];
        return self::resolve($row, $user);
    }

    /** Merge a stored (or posted, for the live preview) row with the template. */
    public static function resolve(array $row, array $user, ?array $tpl = null): array
    {
        $tpl = $tpl ?? RxTemplate::get();
        $val = fn(string $k) => trim((string)($row[$k] ?? ''));
        $lines = fn(string $s) => array_values(array_filter(array_map('trim', preg_split('/\R/u', $s)), 'strlen'));

        $p = [
            'user_id'      => (int)($user['id'] ?? $row['user_id'] ?? 0),
            'title'        => $val('title') !== '' ? $val('title') : $tpl['rx_title'],
            'display_name' => $val('display_name') !== '' ? $val('display_name') : self::plainName((string)($user['full_name'] ?? '')),
            'credentials'  => $lines($val('credentials')),
            'degrees'      => $val('degrees'),
            'phones'       => $val('phones') !== '' ? $val('phones') : $tpl['rx_phones'],
            'address'      => $lines($val('address') !== '' ? $val('address') : $tpl['rx_address']),
            'qr1_url'      => $val('qr1_url') !== '' ? $val('qr1_url') : $tpl['rx_qr1'],
            'qr2_url'      => $val('qr2_url') !== '' ? $val('qr2_url') : $tpl['rx_qr2'],
            'qr_caption'   => $lines($val('qr_caption') !== '' ? $val('qr_caption') : $tpl['rx_qr_caption']),
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

    /** Everyone who may have a sheet, with whether theirs is filled in (admin/rx-templates.php). */
    public static function prescribers(): array
    {
        $in = "'" . implode("','", self::ROLES) . "'";
        return Database::getInstance()->fetchAll(
            "SELECT u.id, u.full_name, u.role, u.is_active, r.panel_mode, r.panel_image, r.updated_at,
                    (COALESCE(r.credentials, '') <> '' OR COALESCE(r.degrees, '') <> '') AS has_details,
                    (SELECT COUNT(*) FROM erp2_prescriptions pr WHERE pr.doctor_id = u.id) AS rx_count
             FROM users u
             LEFT JOIN doctor_rx_profiles r ON r.user_id = u.id
             WHERE u.role IN ({$in})
             ORDER BY u.is_active DESC, u.role = 'admin', u.full_name"
        );
    }

    /**
     * Save the edit form. $file is the uploaded panel image (or null);
     * $dropImage removes the current one. Returns the stored row.
     */
    public static function save(int $userId, array $in, ?array $file, bool $dropImage, int $actorId): array
    {
        $db = Database::getInstance();
        if (!self::mayHave($userId)) {
            throw new RuntimeException('تەنها دکتۆر (یان بەڕێوەبەر) ڕەچەتەی هەیە');
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
            $newFile = RxTemplate::storeUpload($file, 'panel_doctor_' . $userId);
        }
        if ($newFile !== null || $dropImage) {
            self::dropFile($image);
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

    /** Back to the template: the doctor's own fields and picture are removed. */
    public static function reset(int $userId): void
    {
        $old = self::stored($userId);
        self::dropFile((string)($old['panel_image'] ?? ''));
        Database::getInstance()->query("DELETE FROM doctor_rx_profiles WHERE user_id = :id", ['id' => $userId]);
    }

    public static function mayHave(int $userId): bool
    {
        $u = Database::getInstance()->fetch("SELECT role FROM users WHERE id = :id", ['id' => $userId]);
        return $u && in_array($u['role'], self::ROLES, true);
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
        return RxTemplate::isSafePath($path);
    }

    private static function imageExists(string $path): bool
    {
        return $path !== '' && self::isSafePath($path) && is_file(APP_ROOT . '/' . $path);
    }

    private static function dropFile(string $path): void
    {
        if ($path !== '' && self::isSafePath($path)) {
            @unlink(APP_ROOT . '/' . $path);
        }
    }
}
