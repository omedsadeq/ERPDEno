<?php
/**
 * Router for PHP's built-in server (`php -S`), for running Lezan on this PC
 * and publishing it through a Cloudflare Tunnel (server/start-lezan.cmd).
 *
 * The built-in server ignores .htaccess, so the rules that keep secrets and
 * internal code off the web are applied here instead:
 *   - files: .env (DB + S3 keys), dumps, archives, logs, ini, markdown, VCS,
 *     package manifests, stray dev files;
 *   - folders that are code to include, tools to run by hand, or data:
 *     includes, templates, config, classes, services, vendor, database,
 *     migrations, storage (backups, logs), tests (they write to a database),
 *     cron, docs, typesense, fingerprint-sync (device config and queue),
 *     the old dentHisab copy, this server folder;
 *   - uploads: the folders whose own .htaccess denies all (case and X-ray
 *     images, letters, HR documents — the app hands them out after its
 *     checks), and nothing executable is ever run from any upload folder;
 *   - root debug_/test_ pages.
 * Everything else is served as the built-in server would.
 */

// The path as the file system will see it. Not parse_url(): it reads
// "//config/config.php" as a host named "config" and the rest would slip by.
$raw = rawurldecode(explode('?', (string)($_SERVER['REQUEST_URI'] ?? '/'), 2)[0]);
$raw = str_replace('\\', '/', $raw);
$segments = [];
$climbs = false;
foreach (explode('/', $raw) as $seg) {
    $bare = trim($seg);
    if ($bare !== '' && trim($bare, '.') === '' && strlen($bare) > 1) {
        $climbs = true;   // "..", "...": never let a path step out of a folder
    }
    // Windows ignores trailing dots and spaces in names (".env." opens ".env");
    // "" (from "//") and "." name no folder at all.
    $seg = rtrim($seg, '. ');
    if ($seg !== '') {
        $segments[] = $seg;
    }
}
$path = '/' . implode('/', $segments);
$base = (string)end($segments);

$deny = $climbs
    || preg_match('#\.(sql|zip|tar|gz|7z|rar|bak|old|dist|log|sh|bat|cmd|ps1|py|ini|md|lock|sqlite3?|db|env)$#i', $base)
    || preg_match('#^(\.env.*|composer\.(json|lock)|package(-lock)?\.json|\.git.*|\.htaccess|\.user\.ini|nul|_nul|Edit|temp_output\.txt)$#i', $base)
    || preg_match('#^/(includes|templates|config|classes|services|vendor|database|migrations|storage|tests|cron|docs|typesense|fingerprint-sync|dentHisab|server|node_modules|\.git)(/|$)#i', $path)
    || preg_match('#^/(debug_[^/]*|test_[^/]*)\.php$#i', $path)
    || preg_match('#/uploads/(cases|letters|xray|hr_documents)(/|$)#i', $path)
    || preg_match('#/uploads/.*\.(php\d?|phtml|phar|cgi|pl|py|sh|bat|exe)$#i', $path);

if ($deny) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('404 Not Found');
}

return false; // let the built-in server handle it
