<?php // tests/cases/LazyS3Test.php
// Constructing XrayManager must NOT drag in the AWS SDK.
//
// XrayManager is built by 19 call sites — patients/view.php, the accountant
// dashboard, financial summary, PatientFinance, ApprovalQueue, DebtPayment —
// almost all of which only ever read x-ray rows. Building an S3Client in the
// constructor pulled ~150 extra files and ~2.2 MB of SDK source into every one
// of those requests (endpoints.json alone is 826 KB) for ~16 MB of memory and
// ~150 ms that the page never used. S3 work must pay for itself only when a
// file is actually uploaded or fetched.
require_once __DIR__ . '/../../classes/XrayManager.php';
require_once __DIR__ . '/../../classes/S3Uploader.php';

// Simulate a fully configured S3 environment — the branch that used to be slow.
$restore = ['S3_ACCESS_KEY' => getenv('S3_ACCESS_KEY'), 'S3_SECRET_KEY' => getenv('S3_SECRET_KEY')];
putenv('S3_ACCESS_KEY=probe-key');   $_ENV['S3_ACCESS_KEY'] = 'probe-key';
putenv('S3_SECRET_KEY=probe-secret'); $_ENV['S3_SECRET_KEY'] = 'probe-secret';

$xm = new XrayManager();
$t->assertTrue($xm instanceof XrayManager, 'XrayManager constructs with S3 configured');

// The SDK is autoloaded, so "class exists in memory" is the exact signal that
// something touched it. class_exists() with autoload OFF does not load it.
$t->assertSame(false, class_exists('Aws\S3\S3Client', false),
    'constructing XrayManager must not load Aws\S3\S3Client');
$t->assertSame(false, class_exists('GuzzleHttp\Client', false),
    'constructing XrayManager must not load GuzzleHttp\Client');

// The uploader handle itself must still be reachable for real upload work.
$t->assertTrue(S3Uploader::getInstance() instanceof S3Uploader,
    'S3Uploader::getInstance() still returns a usable uploader');
$t->assertSame(false, class_exists('Aws\S3\S3Client', false),
    'merely getting the S3Uploader singleton must not build an S3 client either');

foreach ($restore as $k => $v) {
    if ($v === false) { putenv($k); unset($_ENV[$k]); }
    else { putenv("$k=$v"); $_ENV[$k] = $v; }
}
