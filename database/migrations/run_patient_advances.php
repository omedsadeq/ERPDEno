<?php
/**
 * Runner for 2026_09_22_patient_advances.sql (ئامانەتی نەخۆش).
 *
 * Open it in the browser while signed in as an admin:
 *   /database/migrations/run_patient_advances.php
 *
 * Unlike run_cancellation_migration.php (which is open to anyone who knows the
 * URL), this one refuses to run for anyone but a logged-in admin: it creates
 * money tables, and nothing here should be reachable from outside.
 *
 * Safe to run twice — every statement is skipped if it is already applied.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/Auth.php';

header('Content-Type: text/plain; charset=utf-8');

$auth = new Auth();
if (!$auth->isLoggedIn() || !$auth->isAdmin()) {
    http_response_code(403);
    echo "تەنها ئەدمین دەتوانێت ئەمە جێبەجێ بکات.\n";
    echo "سەرەتا وەک ئەدمین بچۆرە ژوورەوە، پاشان ئەم لینکە بکەرەوە.\n";
    exit;
}

$db = Database::getInstance();
$sqlFile = __DIR__ . '/2026_09_22_patient_advances.sql';
if (!is_file($sqlFile)) {
    echo "Migration file not found: {$sqlFile}\n";
    exit;
}

// The file is our own and contains no strings with semicolons in them, so a
// split on ";\n" is enough — no SQL parser needed. Comment-only chunks are
// dropped so an empty statement is never sent.
$raw = file_get_contents($sqlFile);
$chunks = preg_split('/;\s*\n/', $raw);

$applied = 0;
$skipped = 0;
$failed  = 0;

foreach ($chunks as $chunk) {
    // Strip full-line comments, then see whether anything executable is left.
    $stmt = trim(preg_replace('/^\s*--.*$/m', '', $chunk));
    if ($stmt === '') {
        continue;
    }
    $label = preg_replace('/\s+/', ' ', mb_substr($stmt, 0, 72));
    try {
        $db->query($stmt);
        $applied++;
        echo "OK      : {$label}...\n";
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        // Re-running is expected and must not look like a failure.
        $isAlreadyThere = stripos($msg, 'Duplicate column') !== false
            || stripos($msg, 'already exists') !== false
            || stripos($msg, 'Duplicate key name') !== false;
        if ($isAlreadyThere) {
            $skipped++;
            echo "SKIP    : {$label}...\n";
        } else {
            $failed++;
            echo "FAILED  : {$label}...\n          {$msg}\n";
        }
    }
}

echo "\n----------------------------------------\n";
echo "جێبەجێکرا: {$applied}   ·   پێشتر هەبوو: {$skipped}   ·   سەرنەکەوت: {$failed}\n";

// Prove the three things the rest of the code depends on really landed, rather
// than trusting the statement count.
$checks = [
    'patient_advances (خشتە)'            => "SHOW TABLES LIKE 'patient_advances'",
    'patient_advance_allocations (خشتە)' => "SHOW TABLES LIKE 'patient_advance_allocations'",
    'case_invoices.advance_paid_amount'  => "SHOW COLUMNS FROM case_invoices LIKE 'advance_paid_amount'",
    'v_case_financial_summary (view)'    => "SHOW TABLES LIKE 'v_case_financial_summary'",
];
echo "\nپشکنین:\n";
$allGood = true;
foreach ($checks as $label => $sql) {
    try {
        $ok = (bool)$db->fetch($sql);
    } catch (Throwable $e) {
        $ok = false;
    }
    if (!$ok) { $allGood = false; }
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . "\n";
}

echo "\n" . ($allGood && $failed === 0
    ? "تەواو بوو. ئێستا تایبەتمەندی «ئامانەت» کاردەکات.\n"
    : "تەواو نەبوو — سەرەوە ببینە. تا چارەسەر نەکرێت ئەم تایبەتمەندییە کار ناکات.\n");
