<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('MIGRATIONS_RUNNING',true);
putenv('DB_NAME=denthisab_test'); $_ENV['DB_NAME']='denthisab_test';
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../classes/CashboxLedger.php';
try {
    $result=(new CashboxLedger())->move($argv[1], 'withdrawal', $argv[2], 'Concurrent withdrawal test', $argv[3], (int)$argv[4]);
    echo json_encode(['ok'=>true,'id'=>$result['id']]);
} catch (DomainException $e) { echo json_encode(['ok'=>false]); }
session_destroy();
