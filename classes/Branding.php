<?php
/**
 * Branding — the centre's identity in one place: names, contacts, logo, colours.
 * ناسنامەی سەنتەر: ناو، پەیوەندی، لۆگۆ و ڕەنگ
 *
 * Every value lives in the `settings` table, edited on admin/settings.php
 * (tab «ناسنامە و ڕەنگ») and seeded by
 * database/migrations/2026_10_23_clinic_branding.sql. DEFAULTS are Lezan's own
 * details, so an install nobody has configured yet — or a request that cannot
 * reach the database — prints exactly what Lezan prints.
 *
 * Two kinds of keys:
 *   - required (name, phone 1, address, logo, colours): an empty value falls
 *     back to the default, so a document never prints a blank letterhead;
 *   - optional (phones 2/3, email, social accounts): empty means "not shown".
 *     The default only applies while the key does not exist at all.
 */

require_once __DIR__ . '/Settings.php';

class Branding
{
    const NAME        = 'clinic_name';         // سەنتەری لێزان — titles, login, sidebar
    const NAME_EN     = 'clinic_name_en';
    const SHORT_NAME  = 'clinic_short_name';   // لێزان — «قاسەی لێزان»، «پشکی لێزان»
    const PRINT_NAME  = 'clinic_print_name';   // the letterhead line on receipts and reports
    const PHONE_1     = 'clinic_phone';
    const PHONE_2     = 'clinic_phone_2';
    const PHONE_3     = 'clinic_phone_3';
    const ADDRESS     = 'clinic_address';
    const EMAIL       = 'clinic_email';
    const FACEBOOK    = 'clinic_facebook';
    const INSTAGRAM   = 'clinic_instagram';
    const SOCIAL_3    = 'clinic_social_other';
    const WEBSITE     = 'clinic_website';
    const LOGO        = 'clinic_logo_path';    // relative to APP_ROOT; '' = DEFAULT_LOGO
    const PRIMARY     = 'theme_primary_color'; // the app's accent (buttons, sidebar, tabs…)
    const PRINT_COLOR = 'print_accent_color';  // rules and icons on receipts

    const DEFAULT_LOGO = 'assets/images/logo.png';

    /** Uploaded logos live here (relative to APP_ROOT). */
    const UPLOAD_DIR = 'uploads/branding';

    const DEFAULTS = [
        self::NAME        => 'سەنتەری لێزان',
        self::NAME_EN     => 'Lezan Dental Clinics',
        self::SHORT_NAME  => 'لێزان',
        self::PRINT_NAME  => 'لێزان بۆ پزیشکی ددان',
        self::PHONE_1     => '0750 693 2626',
        self::PHONE_2     => '0750 632 7575',
        self::PHONE_3     => '0751 192 2326',
        self::ADDRESS     => 'سۆران: ترافیک لایەتی دەروازەی سۆران، بەرامبەر میلان کڵاس',
        self::EMAIL       => '',
        self::FACEBOOK    => 'Lezan Dental Clinics',
        self::INSTAGRAM   => 'lezan.dental.clinics',
        self::SOCIAL_3    => 'lezanclinics',
        self::WEBSITE     => 'www.lezandentalclinics.com',
        self::LOGO        => '',
        self::PRIMARY     => '#0078D4',
        self::PRINT_COLOR => '#29a3dc',
    ];

    /** Keys whose empty value means "use the default" rather than "hide it". */
    const REQUIRED = [
        self::NAME, self::NAME_EN, self::SHORT_NAME, self::PHONE_1, self::ADDRESS,
        self::LOGO, self::PRIMARY, self::PRINT_COLOR,
    ];

    /**
     * Lezan's accent palette exactly as style.css defines it. Used verbatim
     * while the primary colour is the default, so nothing shifts by a shade.
     */
    const DEFAULT_THEME = [
        '--win-accent'         => '#0078D4',
        '--win-accent-hover'   => '#1984D8',
        '--win-accent-pressed' => '#006CBD',
        '--win-accent-dark'    => '#005A9E',
        '--win-accent-deep'    => '#0063B1',
        '--win-accent-light'   => '#60CDFF',
        '--text-accent'        => '#0067C0',
        '--win-accent-rgb'     => '0, 120, 212',
    ];

    // ── Raw access ────────────────────────────────────────────────────

    public static function get(string $key): string
    {
        $default = self::DEFAULTS[$key] ?? '';
        // recovery.php runs with a missing or broken database — never query it.
        if (defined('RECOVERY_MODE')) {
            return $default;
        }
        $value = Settings::get($key);
        if ($value === null) {
            return $default;
        }
        // APP_NAME is echoed unescaped in many templates — markup never belongs
        // in a name or a phone number, so it is removed on the way out too.
        $value = trim(str_replace(['<', '>', '"'], '', $value));
        return ($value === '' && in_array($key, self::REQUIRED, true)) ? $default : $value;
    }

    // ── Saving (admin/settings.php) ───────────────────────────────────

    /** Text fields the settings form edits, with their maximum length. */
    const TEXT_FIELDS = [
        self::NAME      => 120, self::NAME_EN   => 120, self::SHORT_NAME => 60, self::PRINT_NAME => 120,
        self::PHONE_1   => 40,  self::PHONE_2   => 40,  self::PHONE_3    => 40, self::ADDRESS    => 300,
        self::EMAIL     => 120, self::FACEBOOK  => 120, self::INSTAGRAM  => 120,
        self::SOCIAL_3  => 120, self::WEBSITE   => 160,
    ];

    const COLOR_FIELDS = [self::PRIMARY, self::PRINT_COLOR];

    /**
     * Save the branding form. A required field left empty stores its default,
     * so the page always shows what is actually printed; an invalid colour
     * keeps the old one. Returns [key => [old, new]] for what changed.
     */
    public static function save(array $input): array
    {
        $changes = [];
        foreach (self::TEXT_FIELDS as $key => $max) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $value = self::clean((string)$input[$key], $max);
            if ($value === '' && in_array($key, self::REQUIRED, true)) {
                $value = self::DEFAULTS[$key];
            }
            self::put($key, $value, 'text', $changes);
        }
        foreach (self::COLOR_FIELDS as $key) {
            $value = trim((string)($input[$key] ?? ''));
            if (self::isHexColor($value)) {
                self::put($key, strtolower($value), 'color', $changes);
            }
        }
        return $changes;
    }

    /** Point the logo setting at an uploaded file ('' = Lezan's default logo). */
    public static function setLogo(string $relative, array &$changes): void
    {
        self::put(self::LOGO, $relative, 'image', $changes);
    }

    /** Every branding value back to Lezan's; the uploaded logo is deleted. */
    public static function reset(): array
    {
        $changes = [];
        $oldLogo = self::get(self::LOGO);
        foreach (self::DEFAULTS as $key => $value) {
            self::put($key, $value, in_array($key, self::COLOR_FIELDS, true) ? 'color' : 'text', $changes);
        }
        self::deleteLogoFile($oldLogo);
        return $changes;
    }

    private static function put(string $key, string $value, string $type, array &$changes): void
    {
        $old = Settings::get($key);
        if ($old !== $value) {
            Settings::set($key, $value, $type);
            $changes[$key] = [$old, $value];
        }
    }

    /** One line of plain text: no markup characters, no line breaks, capped. */
    private static function clean(string $value, int $max): string
    {
        $value = str_replace(['<', '>', '"'], '', $value);
        $value = trim(preg_replace('/\s+/u', ' ', $value));
        return mb_substr($value, 0, $max);
    }

    // ── Names ─────────────────────────────────────────────────────────

    public static function name(): string      { return self::get(self::NAME); }
    public static function nameEn(): string    { return self::get(self::NAME_EN); }
    public static function shortName(): string { return self::get(self::SHORT_NAME); }

    /** Letterhead name; falls back to the centre's name when left empty. */
    public static function printName(): string
    {
        return self::get(self::PRINT_NAME) ?: self::name();
    }

    // ── Contacts ──────────────────────────────────────────────────────

    public static function phone(int $n = 1): string
    {
        $key = [1 => self::PHONE_1, 2 => self::PHONE_2, 3 => self::PHONE_3][$n] ?? null;
        return $key ? self::get($key) : '';
    }

    /** @return string[] the non-empty phone numbers, in order */
    public static function phones(): array
    {
        return array_values(array_filter([self::phone(1), self::phone(2), self::phone(3)], 'strlen'));
    }

    public static function address(): string { return self::get(self::ADDRESS); }
    public static function email(): string   { return self::get(self::EMAIL); }

    /** @return array<string,string> icon => handle, only the ones filled in */
    public static function socials(): array
    {
        return array_filter([
            '📘' => self::get(self::FACEBOOK),
            '📷' => self::get(self::INSTAGRAM),
            '👤' => self::get(self::SOCIAL_3),
            '🌐' => self::get(self::WEBSITE),
        ], 'strlen');
    }

    // ── Logo ──────────────────────────────────────────────────────────

    /** Relative path of the logo in use — the uploaded one if it still exists. */
    public static function logoRelative(): string
    {
        $path = self::get(self::LOGO);
        if ($path !== self::DEFAULT_LOGO && self::isSafeLogoPath($path) && is_file(APP_ROOT . '/' . $path)) {
            return $path;
        }
        return self::DEFAULT_LOGO;
    }

    public static function hasCustomLogo(): bool
    {
        return self::logoRelative() !== self::DEFAULT_LOGO;
    }

    /** Browser URL, versioned by mtime so a new upload is never served stale. */
    public static function logoUrl(): string
    {
        $rel   = self::logoRelative();
        $mtime = @filemtime(APP_ROOT . '/' . $rel);
        return APP_URL . '/' . $rel . ($mtime ? '?v=' . $mtime : '');
    }

    /** Filesystem path, for mPDF. */
    public static function logoPath(): string
    {
        return APP_ROOT . '/' . self::logoRelative();
    }

    /** MIME type of the logo in use (favicons and the PWA manifest need it). */
    public static function logoMime(): string
    {
        return strtolower(pathinfo(self::logoRelative(), PATHINFO_EXTENSION)) === 'png' ? 'image/png' : 'image/jpeg';
    }

    /** Only files under the upload folder (or the bundled default) are ever served. */
    public static function isSafeLogoPath(string $path): bool
    {
        if ($path === self::DEFAULT_LOGO) {
            return true;
        }
        return strpos($path, '..') === false
            && preg_match('#^' . preg_quote(self::UPLOAD_DIR, '#') . '/[A-Za-z0-9_.-]+\.(png|jpe?g)$#', $path) === 1;
    }

    /**
     * Validate and store an uploaded logo (PNG/JPG, ≤ 3 MB). Returns the new
     * relative path; throws RuntimeException with a Kurdish message on failure.
     * The caller saves the path and then removes the previous file.
     */
    public static function storeLogoUpload(array $file): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('بارکردنی لۆگۆ سەرکەوتوو نەبوو');
        }
        if ($file['size'] > 3 * 1024 * 1024) {
            throw new RuntimeException('قەبارەی لۆگۆ دەبێت لە ٣ مێگابایت کەمتر بێت');
        }
        // Trust the bytes, not the file name or the browser's MIME type.
        $info = @getimagesize($file['tmp_name']);
        $ext  = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg'][$info[2] ?? 0] ?? null;
        if ($ext === null) {
            throw new RuntimeException('تەنها وێنەی PNG یان JPG وەردەگیرێت');
        }

        $dir = APP_ROOT . '/' . self::UPLOAD_DIR;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('فۆڵدەری لۆگۆ دروست نەکرا');
        }
        $rel = self::UPLOAD_DIR . '/logo_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], APP_ROOT . '/' . $rel)) {
            throw new RuntimeException('پاشەکەوتکردنی لۆگۆ سەرکەوتوو نەبوو');
        }
        return $rel;
    }

    /** Delete an uploaded logo file; the bundled default is never touched. */
    public static function deleteLogoFile(string $rel): void
    {
        if ($rel !== '' && $rel !== self::DEFAULT_LOGO && self::isSafeLogoPath($rel)) {
            @unlink(APP_ROOT . '/' . $rel);
        }
    }

    // ── Colours ───────────────────────────────────────────────────────

    public static function isHexColor(string $value): bool
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1;
    }

    public static function primaryColor(): string
    {
        $hex = self::get(self::PRIMARY);
        return self::isHexColor($hex) ? strtoupper($hex) : self::DEFAULTS[self::PRIMARY];
    }

    public static function printColor(): string
    {
        $hex = self::get(self::PRINT_COLOR);
        return self::isHexColor($hex) ? $hex : self::DEFAULTS[self::PRINT_COLOR];
    }

    /**
     * The accent custom properties for a primary colour. The shades keep the
     * same relationship to the primary that Lezan's palette has (dark ≈ 75 %
     * brightness, hover ≈ 10 % towards white …), so a new colour reads the
     * same way the blue does.
     */
    public static function themeVars(?string $primary = null): array
    {
        $hex = $primary ?? self::primaryColor();
        if (!self::isHexColor($hex) || strcasecmp($hex, self::DEFAULTS[self::PRIMARY]) === 0) {
            return self::DEFAULT_THEME;
        }
        [$r, $g, $b] = self::rgb($hex);
        return [
            '--win-accent'         => strtoupper($hex),
            '--win-accent-hover'   => self::mixWhite($hex, 0.10),
            '--win-accent-pressed' => self::darken($hex, 0.89),
            '--win-accent-dark'    => self::darken($hex, 0.75),
            '--win-accent-deep'    => self::darken($hex, 0.83),
            '--win-accent-light'   => self::mixWhite($hex, 0.45),
            '--text-accent'        => self::darken($hex, 0.88),
            '--win-accent-rgb'     => "$r, $g, $b",
        ];
    }

    /**
     * <style> block overriding the accent on :root. Emit it after style.css.
     * Empty while the colour is the default — style.css already has it.
     */
    public static function themeStyleTag(): string
    {
        $vars = self::themeVars();
        if ($vars === self::DEFAULT_THEME) {
            return '';
        }
        $css = '';
        foreach ($vars as $name => $value) {
            $css .= $name . ':' . $value . ';';
        }
        return '<style id="brand-theme">:root{' . $css . '}</style>';
    }

    /** True for colours too light to carry white text (the buttons use white). */
    public static function isLight(string $hex): bool
    {
        [$r, $g, $b] = self::rgb($hex);
        return (0.299 * $r + 0.587 * $g + 0.114 * $b) > 186;
    }

    /** @return int[] [r, g, b] */
    public static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    private static function toHex(array $rgb): string
    {
        return '#' . implode('', array_map(
            static fn($c) => str_pad(strtoupper(dechex(max(0, min(255, (int)round($c))))), 2, '0', STR_PAD_LEFT),
            $rgb
        ));
    }

    private static function darken(string $hex, float $factor): string
    {
        return self::toHex(array_map(static fn($c) => $c * $factor, self::rgb($hex)));
    }

    private static function mixWhite(string $hex, float $amount): string
    {
        return self::toHex(array_map(static fn($c) => $c + (255 - $c) * $amount, self::rgb($hex)));
    }
}
