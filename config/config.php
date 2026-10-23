<?php
/**
 * Smart Dental Clinic System - Configuration File
 * سیستەمی زیرەکی بەڕێوەبردنی سەنتەری ددان
 */

// Load environment variables from .env file
$envPath = dirname(__DIR__) . '/.env';
$autoloadPath = dirname(__DIR__) . '/vendor/autoload.php';
if (file_exists($envPath) && file_exists($autoloadPath)) {
    require_once $autoloadPath;
    if (class_exists('Dotenv\Dotenv')) {
        $dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
        $dotenv->safeLoad();
    }
}

// Environment detection: local XAMPP vs live hosting
// NOTE: an empty HTTP_HOST must NOT count as local — CLI scripts on the live
// server (cron/backup-cron.php) have no HTTP_HOST and would otherwise connect
// with the local credentials. Local CLI is already covered by the Windows
// DIRECTORY_SEPARATOR check (local dev = Windows XAMPP, host = Linux).
$serverHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$isLocal = DIRECTORY_SEPARATOR === '\\'
    || strpos($serverHost, 'localhost') === 0
    || strpos($serverHost, '127.0.0.1') === 0
    || strpos($serverHost, '::1') === 0
    || substr($serverHost, -6) === '.local'
    || substr($serverHost, -5) === '.test';
define('IS_LOCAL', $isLocal);

// Database Configuration
$dbDefaults = $isLocal
    ? ['host' => 'localhost', 'name' => 'leza',                 'user' => 'root',                  'pass' => '']
    : ['host' => 'localhost', 'name' => 'leza', 'user' => 'root', 'pass' => ''];

define('DB_HOST', $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: $dbDefaults['host']);
define('DB_NAME', $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: $dbDefaults['name']);
define('DB_USER', $_ENV['DB_USER'] ?? getenv('DB_USER') ?: $dbDefaults['user']);
define('DB_PASS', $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?: $dbDefaults['pass']);
define('DB_CHARSET', $_ENV['DB_CHARSET'] ?? getenv('DB_CHARSET') ?: 'utf8mb4');

// Application Configuration
// APP_NAME / APP_NAME_EN / CLINIC_NAME are defined at the end of this file,
// from the settings table (classes/Branding.php), once migrations have run.
define('APP_VERSION', '1.0.0');
define('APP_ROOT', dirname(__DIR__));
require_once __DIR__ . '/../classes/Branding.php';

if (!function_exists('detect_app_url')) {
    function detect_app_url(): string
    {
        $configuredUrl = trim((string)($_ENV['APP_URL'] ?? getenv('APP_URL') ?: ''));
        $parsedUrl = $configuredUrl !== '' ? parse_url($configuredUrl) : false;

        if (
            is_array($parsedUrl)
            && in_array(strtolower($parsedUrl['scheme'] ?? ''), ['http', 'https'], true)
            && !empty($parsedUrl['host'])
            && strpos($parsedUrl['host'], '_') === false
        ) {
            return rtrim($configuredUrl, '/');
        }

        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($host !== '' && preg_match('/^[A-Za-z0-9.-]+(?::[0-9]+)?$/', $host)) {
            $https = (
                (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            );

            $basePath = '';
            $documentRoot = isset($_SERVER['DOCUMENT_ROOT'])
                ? str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']) ?: $_SERVER['DOCUMENT_ROOT'])
                : '';
            $appRoot = str_replace('\\', '/', APP_ROOT);

            if ($documentRoot !== '' && stripos($appRoot, rtrim($documentRoot, '/')) === 0) {
                $basePath = trim(substr($appRoot, strlen(rtrim($documentRoot, '/'))), '/');
                $basePath = $basePath !== '' ? '/' . $basePath : '';
            }

            return ($https ? 'https://' : 'http://') . $host . $basePath;
        }

        return 'http://localhost:8080/dentHisab';
    }
}

define('APP_URL', detect_app_url());

// Session Configuration
define('SESSION_NAME', 'dental_clinic_session');
define('SESSION_LIFETIME', 3600 * 8); // 8 hours

// Currency Configuration
// System works with IQD only — amounts are displayed as plain numbers,
// no currency symbol is printed anywhere. Keep CURRENCY_SYMBOL empty.
define('CURRENCY', 'IQD');
define('CURRENCY_SYMBOL', '');

// Date/Time Configuration
define('TIMEZONE', 'Asia/Baghdad');
date_default_timezone_set(TIMEZONE);

// File Upload Configuration
define('UPLOAD_PATH', APP_ROOT . '/uploads/');
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'pdf']);

// Case-image storage target.
//   'local' → saved under uploads/cases/<case_id>/ on this server's disk
//   's3'    → uploaded to iDrive e2 (S3-compatible)
// Default: local on the dev machine, S3 on the live host. Override in .env
// with CASE_IMAGES_STORAGE=local|s3. Existing rows keep working either way —
// each case_images row remembers where it lives (s3_key set ⇒ S3).
$caseImgStore = strtolower(trim((string)($_ENV['CASE_IMAGES_STORAGE'] ?? getenv('CASE_IMAGES_STORAGE') ?: '')));
if (!in_array($caseImgStore, ['local', 's3'], true)) {
    $caseImgStore = IS_LOCAL ? 'local' : 's3';
}
define('CASE_IMAGES_STORAGE', $caseImgStore);

// Pagination
define('ITEMS_PER_PAGE', 15);

// Minimum characters before the clinic-wide patient directory
// ("نەخۆشەکانی تر" on patients/index.php) queries the server. The list is not
// preloaded: with thousands of patients a doctor is always looking for one
// known person, so results arrive only after typing. patients/index.php,
// patients/ajax_search.php and the front-end debounce all read this constant.
define('DIRECTORY_SEARCH_MIN_CHARS', 3);

// Role Constants
define('ROLE_ADMIN', 'admin');
define('ROLE_DOCTOR', 'doctor');
define('ROLE_RECEPTIONIST', 'receptionist');
define('ROLE_XRAY_DEPARTMENT', 'xray_department');
define('ROLE_ACCOUNTANT', 'accountant');

// X-Ray Types
$XRAY_TYPES = [
    'panoramic' => 'پانۆرامیک',
    'cbct' => 'CBCT',
    'periapical' => 'پەریئەپیکاڵ',
    'cephalometric' => 'سێفالۆمەتریک'
];

// CBCT (3D) options — scope × with/without report, each with its own price.
// Stored on xray_requests.cbct_option; the price row is xray_pricing
// pricing_mode 'cbct_' . key (see admin/xray-pricing.php).
define('XRAY_CBCT_OPTIONS', [
    'full_report'    => ['scope' => 'full', 'report' => true,  'en' => 'Full Mouth — With Report', 'ku' => 'هەموو دەم — بە ڕاپۆرت'],
    'full_no_report' => ['scope' => 'full', 'report' => false, 'en' => 'Full Mouth — No Report',   'ku' => 'هەموو دەم — بێ ڕاپۆرت'],
    '2seg_report'    => ['scope' => '2seg', 'report' => true,  'en' => '2 Segments — With Report', 'ku' => '٢ بەش — بە ڕاپۆرت'],
    '2seg_no_report' => ['scope' => '2seg', 'report' => false, 'en' => '2 Segments — No Report',   'ku' => '٢ بەش — بێ ڕاپۆرت'],
    '1seg_report'    => ['scope' => '1seg', 'report' => true,  'en' => '1 Segment — With Report',  'ku' => '١ بەش — بە ڕاپۆرت'],
    '1seg_no_report' => ['scope' => '1seg', 'report' => false, 'en' => '1 Segment — No Report',    'ku' => '١ بەش — بێ ڕاپۆرت'],
]);
define('XRAY_CBCT_SCOPES', [
    'full' => ['en' => 'Full Mouth', 'ku' => 'هەموو دەم'],
    '2seg' => ['en' => '2 Segments', 'ku' => '٢ بەش'],
    '1seg' => ['en' => '1 Segment',  'ku' => '١ بەش'],
]);

// X-Ray Reasons
$XRAY_REASONS = [
    'implant' => 'چاندنی ددان',
    'pathology' => 'پاتۆلۆجی',
    'impaction' => 'ددانی شاراوە',
    'trauma' => 'تراوما/برین',
    'endo' => 'ئێندۆ (دەمارکێشان)',
    'other' => 'هیتر'
];

// X-Ray Request Status
$XRAY_STATUS = [
    'pending' => 'چاوەڕوان',
    'pending_accountant' => 'چاوەڕوانی ژمێریار',
    'pending_xray' => 'چاوەڕوانی ئێکسڕەی',
    'in_progress' => 'لە پڕۆسەدا',
    'completed' => 'تەواوبوو',
    'cancelled' => 'هەڵوەشاوە'
];

// Payment Types
define('PAYMENT_CASH', 'cash');
define('PAYMENT_CREDIT', 'credit');
define('PAYMENT_INSTALLMENT', 'installment');

// Case Status
define('CASE_OPEN', 'open');
define('CASE_CLOSED', 'closed');
define('CASE_PENDING', 'pending');

// File/Folder Status
define('FOLDER_ACTIVE', 'active');
define('FOLDER_ARCHIVED', 'archived');

// Medical Conditions (for alerts)
$MEDICAL_CONDITIONS = [
    'diabetes' => 'شەکرە',
    'blood_pressure' => 'پەستانی خوێن',
    'heart_disease' => 'نەخۆشی دڵ',
    'allergy' => 'حەساسیەت',
    'bleeding_disorder' => 'کێشەی خوێنڕۆیی',
    'pregnancy' => 'دووگیانی',
    'other' => 'هی تر'
];

// Tooth Surfaces
$TOOTH_SURFACES = [
    'mesial' => 'میزیاڵ',
    'distal' => 'دیستاڵ',
    'occlusal' => 'ئۆکلۆزاڵ',
    'lingual' => 'لینگواڵ',
    'buccal' => 'بوکاڵ'
];

// Error Reporting.
// Errors stay visible on the dev machine, but must never be printed on the
// live host: a warning echoed mid-page breaks JSON/AJAX responses, corrupts
// PDF and file downloads, and leaks file paths, SQL and patient data to
// whoever triggered it. They are logged instead.
error_reporting(E_ALL);
if (IS_LOCAL) {
    ini_set('display_errors', '1');
} else {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    if (!ini_get('error_log')) {
        ini_set('error_log', APP_ROOT . '/storage/php-errors.log');
    }
}

// Start session (hardened: strict mode, HttpOnly + SameSite cookie)
if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', (string)SESSION_LIFETIME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off'),
    ]);
    session_start();
}

// ── Maintenance mode ───────────────────────────────────────────────
// Enabled briefly by the backup module while a database restore runs —
// non-admins get a 503 so nobody writes into a half-restored database.
// A flag older than an hour means a restore crashed; it is auto-removed.
$maintenanceFlagFile = APP_ROOT . '/storage/maintenance.flag';
if (PHP_SAPI !== 'cli' && !defined('BACKUP_CRON') && file_exists($maintenanceFlagFile)) {
    if (time() - (int)@filemtime($maintenanceFlagFile) > 3600) {
        @unlink($maintenanceFlagFile);
    } elseif (($_SESSION['role'] ?? '') !== ROLE_ADMIN
        && !in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), ['login.php', 'logout.php', 'recovery.php'], true)) {
        http_response_code(503);
        header('Retry-After: 120');
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="ku" dir="rtl"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . '<title>چاکسازی - ' . htmlspecialchars(Branding::name()) . '</title></head>'
            . '<body style="display:flex;align-items:center;justify-content:center;min-height:100vh;'
            . 'margin:0;background:#f3f3f3;font-family:Tahoma,Arial,sans-serif;text-align:center">'
            . '<div><div style="font-size:52px">🛠️</div>'
            . '<h2 style="color:#333">سیستەم لە چاکسازیدایە</h2>'
            . '<p style="color:#666">داتابەیس لە ئێستادا نوێ دەکرێتەوە. تکایە دوای چەند خولەکێک دووبارە هەوڵ بدەرەوە.</p>'
            . '</div></body></html>';
        exit;
    }
}
unset($maintenanceFlagFile);

// Never let browsers, proxies or the PWA service worker store dynamic PHP
// responses (pages, ajax/, api/) — patient/payment data must always be fresh
// and unreachable after logout. Static assets are served by Apache directly
// and are unaffected.
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

// ── Output compression ────────────────────────────────────────────
// Pages here are large: waiting-room.php alone emits ~400 KB of HTML with
// ~70 KB of inline JS and ~35 KB of inline CSS. gzip removes about 90% of
// that. Done in PHP rather than via mod_deflate so it works regardless of
// which Apache modules the host has enabled.
//
// The callback deliberately refuses to touch anything that is not text: the
// file gateway (file.php) and backup downloads stream binary with their own
// Content-Length, and compressing those would corrupt the length.
if (!function_exists('app_compress_output')) {
    function app_compress_output($buffer) {
        if ($buffer === '' || headers_sent()) {
            return $buffer;
        }
        $contentType = '';
        foreach (headers_list() as $h) {
            $lower = strtolower($h);
            if (strpos($lower, 'content-length:') === 0) {
                return $buffer;   // caller declared a byte count — leave it alone
            }
            if (strpos($lower, 'content-encoding:') === 0) {
                return $buffer;   // already encoded by something else
            }
            if (strpos($lower, 'content-type:') === 0) {
                $contentType = trim(substr($lower, 13));
            }
        }
        // No explicit type means PHP's default text/html.
        if ($contentType !== ''
            && !preg_match('#^(text/|application/(json|javascript|xml|xhtml\+xml)|image/svg\+xml)#', $contentType)) {
            return $buffer;
        }
        $packed = gzencode($buffer, 5);
        if ($packed === false || strlen($packed) >= strlen($buffer)) {
            return $buffer;
        }
        header('Content-Encoding: gzip');
        header('Vary: Accept-Encoding');
        header('Content-Length: ' . strlen($packed));
        return $packed;
    }
}

if (PHP_SAPI !== 'cli'
    && !defined('BACKUP_CRON')
    && !headers_sent()
    && extension_loaded('zlib')
    && !ini_get('zlib.output_compression')
    && stripos((string)($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''), 'gzip') !== false) {
    ob_start('app_compress_output');
}

// Security / audit helpers (available on every page)
require_once __DIR__ . '/../classes/Csrf.php';
require_once __DIR__ . '/../classes/AuditLog.php';
require_once __DIR__ . '/../classes/DoctorSync.php';
require_once __DIR__ . '/../classes/PatientFlow.php';
require_once __DIR__ . '/../classes/AccessGuard.php';

// ── Static asset URLs ─────────────────────────────────────────────
// .htaccess hands CSS/JS out as "immutable" for a year and sw.js keeps its own
// copy, so a browser never asks again for a file at an unchanged URL: an edited
// script would only reach a PC after its cache is cleared. The file's
// modification time in the URL makes every edit a new address both caches have
// to fetch, while an unchanged file stays cached as before.
if (!function_exists('asset_url')) {
    function asset_url(string $path): string {
        $path  = ltrim($path, '/');
        $mtime = @filemtime(__DIR__ . '/../' . $path);
        return APP_URL . '/' . $path . ($mtime ? '?v=' . $mtime : '');
    }
}

// ── Work-type page scopes ─────────────────────────────────────────
// A work type added with the "+" button belongs to the page it was added
// from; only the seeded 'all' types show up everywhere.
define('WORK_TYPE_SCOPES', [
    'all', 'new_visit', 'edit_visit', 'visits', 'appointments', 'patients', 'waiting_room'
]);

/** Whitelist a scope key — anything unknown falls back to the global 'all'. */
function work_type_scope($scope) {
    $scope = is_string($scope) ? trim($scope) : '';
    return in_array($scope, WORK_TYPE_SCOPES, true) ? $scope : 'all';
}

/**
 * SQL condition selecting the types visible on one page: the global ones,
 * the ones added on that page, plus $keepIds — ids already saved on a record
 * being edited, which must stay in the dropdown even when they belong to
 * another page (otherwise editing would silently clear them).
 *
 * Returns a literal: the scope is whitelisted and the ids are cast to int,
 * so nothing user-controlled reaches the query.
 */
function work_type_scope_sql($scope, $keepIds = [], $alias = '') {
    $prefix = $alias !== '' ? $alias . '.' : '';
    $scope  = work_type_scope($scope);

    $cond = $scope === 'all'
        ? "{$prefix}scope = 'all'"
        : "{$prefix}scope IN ('all', '{$scope}')";

    $ids = array_values(array_unique(array_filter(
        array_map('intval', (array)$keepIds),
        fn($v) => $v > 0
    )));
    if ($ids) {
        $cond .= " OR {$prefix}id IN (" . implode(',', $ids) . ")";
    }
    return "({$cond})";
}

// ── Auto-run pending database migrations ──────────────────────────
// Skipped in RECOVERY_MODE (recovery.php): with a missing/empty database
// there is no schema to migrate, and Database::getInstance() must not run.
if (!defined('MIGRATIONS_RUNNING') && !defined('RECOVERY_MODE')) {
    define('MIGRATIONS_RUNNING', true);
    require_once __DIR__ . '/../classes/Database.php';
    require_once __DIR__ . '/../classes/MigrationRunner.php';
    $migrationRunner = new MigrationRunner(
        Database::getInstance()->getConnection(),
        __DIR__ . '/../database/migrations',
        __DIR__ . '/../storage'
    );
    $migrationRunner->run();
    unset($migrationRunner);
}

// ── Clinic identity ───────────────────────────────────────────────
// Edited on admin/settings.php (tab «ناسنامە و ڕەنگ»); Lezan's details when
// unset. Read after the migrations so a fresh deploy already has the keys.
if (!defined('APP_NAME')) {
    define('APP_NAME', Branding::name());
    define('APP_NAME_EN', Branding::nameEn());
    define('CLINIC_NAME', Branding::name());
}
