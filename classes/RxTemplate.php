<?php
/**
 * RxTemplate — the clinic's prescription template: everything on the A5 sheet
 * (includes/rx_sheet.php) that is not one doctor's own. Edited on
 * admin/rx-templates.php («قاڵبی ڕەچەتە»), stored in Settings as rx_* keys.
 *
 * An empty value means «the original design» (DEFAULTS, the sheet as on
 * «lezan a5 v3.pdf»), so reset() is simply emptying them all. The three
 * pictures can each be the original, an uploaded one, or hidden ('-').
 * A doctor's own fields (classes/RxProfile.php) win over the ones here.
 */

require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/Branding.php';

class RxTemplate
{
    const UPLOAD_DIR = 'uploads/rx';
    const HIDDEN     = '-';

    /** The pictures: key => the original file. */
    const IMAGES = [
        'rx_logo'        => 'assets/images/rx/logo.png',
        'rx_image_left'  => 'assets/images/rx/implant.png',
        'rx_image_right' => 'assets/images/rx/braces.png',
    ];

    /** The text fields: key => max length. */
    const TEXTS = [
        'rx_line1'      => 60,
        'rx_line2'      => 80,
        'rx_title'      => 60,
        'rx_qr1'        => 500,
        'rx_qr2'        => 500,
        'rx_qr_caption' => 150,
        'rx_address'    => 255,
        'rx_phones'     => 150,
        'rx_warning'    => 300,
        'rx_sign'       => 60,
    ];
    const COLORS = ['rx_color_frame' => '#0e74a4', 'rx_color_text' => '#296fa0'];

    const DEFAULT_QR1     = 'https://lezan.zediamarketing.com/';
    const DEFAULT_QR2     = 'https://lezanclinics.carrd.co/';
    const DEFAULT_CAPTION = "سکان بکە\nو زیاتر\nبمانناسە";
    const DEFAULT_WARNING = 'ئەگەر هەستیاریت بە دەرمانێک هەبوو، ڕایبگرە و پەیوەندیمان پێوە بکە: {phone}';

    /** The original design's value for a text key (what an empty field prints). */
    public static function defaultText(string $key): string
    {
        $short = Branding::shortName();
        $print = Branding::printName();
        switch ($key) {
            case 'rx_line1':      return $short;
            case 'rx_line2':      return strpos($print, $short) === 0 ? trim(mb_substr($print, mb_strlen($short))) : $print;
            case 'rx_title':      return 'دکتۆر';
            case 'rx_qr1':        return self::DEFAULT_QR1;
            case 'rx_qr2':        return self::DEFAULT_QR2;
            case 'rx_qr_caption': return self::DEFAULT_CAPTION;
            case 'rx_address':    return Branding::address();
            case 'rx_phones':     return implode(' - ', array_slice(Branding::phones(), 0, 2));
            case 'rx_warning':    return self::DEFAULT_WARNING;
            case 'rx_sign':       return 'واژۆی دکتۆر';
        }
        return '';
    }

    /** The template as the sheet uses it: every field filled, pictures as URLs ('' = hidden). */
    public static function get(): array
    {
        $t = [];
        foreach (self::TEXTS as $k => $_) {
            $t[$k] = self::textValue((string)Settings::get($k, ''), $k);
        }
        foreach (self::COLORS as $k => $def) {
            $v = strtolower(trim((string)Settings::get($k, '')));
            $t[$k] = preg_match('/^#[0-9a-f]{6}$/', $v) ? $v : $def;
        }
        foreach (self::IMAGES as $k => $_) {
            $t[$k] = self::imageUrl($k);
        }
        $t['rx_warning'] = str_replace('{phone}', Branding::phone(1), $t['rx_warning']);
        return $t;
    }

    /** A stored text as printed: '' → the original, '-' → nothing (the line is left out). */
    public static function textValue(string $stored, string $key): string
    {
        $v = trim($stored);
        return $v === self::HIDDEN ? '' : ($v !== '' ? $v : self::defaultText($key));
    }

    /** What is stored for the edit form: '' where the original is used. */
    public static function stored(): array
    {
        $s = [];
        foreach (array_merge(array_keys(self::TEXTS), array_keys(self::COLORS), array_keys(self::IMAGES)) as $k) {
            $s[$k] = (string)Settings::get($k, '');
        }
        return $s;
    }

    /** A picture's URL: the uploaded one, the original, or '' when hidden. */
    public static function imageUrl(string $key): string
    {
        $v = (string)Settings::get($key, '');
        if ($v === self::HIDDEN) {
            return '';
        }
        if ($v !== '' && self::isSafePath($v) && is_file(APP_ROOT . '/' . $v)) {
            return APP_URL . '/' . $v . '?v=' . filemtime(APP_ROOT . '/' . $v);
        }
        // the logo: a centre that uploaded its own (settings → clinic) gets it
        if ($key === 'rx_logo' && Branding::hasCustomLogo()) {
            return Branding::logoUrl();
        }
        return APP_URL . '/' . self::IMAGES[$key];
    }

    /**
     * Save the form. $images[key] is 'keep' | 'default' | 'hide' | 'upload'
     * (with $files[key]). Returns nothing; throws RuntimeException.
     */
    public static function save(array $in, array $images, array $files, int $actorId): void
    {
        foreach (self::TEXTS as $k => $max) {
            if (!array_key_exists($k, $in)) {
                continue;
            }
            $v = trim(str_replace(["\r\n", '<', '>'], ["\n", '', ''], (string)$in[$k]));
            if (in_array($k, ['rx_qr1', 'rx_qr2'], true) && $v !== '' && !preg_match('#^(https?://|www\.)\S+$#i', $v)) {
                throw new RuntimeException('لینکی QR دەبێت بە http:// یان https:// دەست پێبکات');
            }
            // the original's own text is stored as «empty», so a later change of
            // the clinic's name or phones still reaches the sheet
            Settings::set($k, $v === self::defaultText($k) ? '' : mb_substr($v, 0, $max), 'text');
        }
        foreach (self::COLORS as $k => $def) {
            $v = strtolower(trim((string)($in[$k] ?? '')));
            if (preg_match('/^#[0-9a-f]{6}$/', $v)) {
                Settings::set($k, $v === $def ? '' : $v, 'color');
            }
        }
        foreach (self::IMAGES as $k => $_) {
            $act = $images[$k] ?? 'keep';
            $old = (string)Settings::get($k, '');
            if ($act === 'upload' && !empty($files[$k]) && ($files[$k]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $new = self::storeUpload($files[$k], str_replace('rx_', 'tpl_', $k));
                self::dropFile($old);
                Settings::set($k, $new, 'image');
            } elseif ($act === 'hide') {
                self::dropFile($old);
                Settings::set($k, self::HIDDEN, 'image');
            } elseif ($act === 'default') {
                self::dropFile($old);
                Settings::set($k, '', 'image');
            }
        }
    }

    /** The original design again: every rx_* value emptied, uploaded pictures deleted. */
    public static function reset(): void
    {
        foreach (self::IMAGES as $k => $_) {
            self::dropFile((string)Settings::get($k, ''));
        }
        foreach (array_merge(array_keys(self::TEXTS), array_keys(self::COLORS), array_keys(self::IMAGES)) as $k) {
            Settings::set($k, '');
        }
    }

    public static function isSafePath(string $path): bool
    {
        return strpos($path, '..') === false
            && preg_match('#^' . preg_quote(self::UPLOAD_DIR, '#') . '/[A-Za-z0-9_.-]+\.(png|jpe?g)$#', $path) === 1;
    }

    /** Validate (PNG/JPG by its bytes, ≤ 6 MB) and store an uploaded picture. */
    public static function storeUpload(array $file, string $prefix): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('بارکردنی وێنە سەرکەوتوو نەبوو');
        }
        if ($file['size'] > 6 * 1024 * 1024) {
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
        $rel = self::UPLOAD_DIR . '/' . preg_replace('/[^a-z0-9_]/i', '', $prefix) . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], APP_ROOT . '/' . $rel)) {
            throw new RuntimeException('پاشەکەوتکردنی وێنە سەرکەوتوو نەبوو');
        }
        return $rel;
    }

    private static function dropFile(string $path): void
    {
        if ($path !== '' && $path !== self::HIDDEN && self::isSafePath($path)) {
            @unlink(APP_ROOT . '/' . $path);
        }
    }
}
