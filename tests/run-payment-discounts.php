<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/TestCase.php';
require __DIR__ . '/fixtures.php';
$t = new TestCase();
require __DIR__ . '/cases/PaymentDiscountTest.php';
require __DIR__ . '/cases/DoctorPaymentDialogTest.php';
require __DIR__ . '/cases/PaymentReceiptDraftTest.php';
require __DIR__ . '/cases/PaymentApprovalPrintTest.php';
echo "{$t->assertions} assertions, {$t->failures} failures\n";
exit($t->failures ? 1 : 0);
