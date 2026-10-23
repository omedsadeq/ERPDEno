<?php // tests/run.php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/TestCase.php';
require __DIR__ . '/fixtures.php';
$t = new TestCase(); $files = glob(__DIR__ . '/cases/*Test.php'); sort($files);
foreach ($files as $f) { echo basename($f), "\n"; require $f; }   // each file uses $t
echo "\n{$t->assertions} assertions, {$t->failures} failures\n";
exit($t->failures ? 1 : 0);
