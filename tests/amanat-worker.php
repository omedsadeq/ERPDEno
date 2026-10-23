<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('MIGRATIONS_RUNNING', true);
putenv('DB_NAME=denthisab_test'); $_ENV['DB_NAME']='denthisab_test';
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../classes/PatientAdvance.php';
echo json_encode((new PatientAdvance())->applyToInvoice((int)$argv[1], (float)$argv[2], (int)$argv[3], $argv[4]));
session_destroy();
