<?php // tests/cases/TodayReceiptTest.php
// Where work without a named receipt is filed (CaseInvoice::getOrCreateActiveDraft):
// today's open receipt, else a fresh one for today — never a draft left unsent
// from an earlier day, which would date (and count) today's work on that day.
// A historical case's very first receipt is the old work day; after that, today.
require_once __DIR__ . '/../../classes/CaseInvoice.php';

(function () use ($t) {
    $db = fx_db(); $ci = new CaseInvoice();
    $doc = fx_user('doctor');
    $case = fx_case(fx_folder(fx_patient(), $doc));
    $dateOf = fn($id) => $db->fetch('SELECT work_date, accounting_status FROM case_invoices WHERE id = ?', [$id]);

    // a draft left unsent yesterday
    $stale = fx_invoice($case, $doc, 'draft', 'draft', 0);
    $db->update('case_invoices', ['work_date' => date('Y-m-d', strtotime('-1 day'))], 'id = ?', [$stale]);
    $target = $ci->getOrCreateActiveDraft($case, $doc);
    $t->assertTrue($target !== $stale, 'stale: yesterday\'s unsent draft does not take today\'s work');
    $t->assertSame(date('Y-m-d'), $dateOf($target)['work_date'], 'stale: a receipt for today is made instead');
    $t->assertSame('draft', $dateOf($target)['accounting_status'], 'today: it is a draft');
    $t->assertSame($target, $ci->getOrCreateActiveDraft($case, $doc), 'today: the same receipt is reused, not a second one');

    // today's receipt sent: the next work opens another one for today
    $db->update('case_invoices', ['status' => 'sent', 'accounting_status' => 'sent'], 'id = ?', [$target]);
    $next = $ci->getOrCreateActiveDraft($case, $doc);
    $t->assertTrue($next !== $target && $next !== $stale, 'sent: a sent receipt is never written on');
    $t->assertSame(date('Y-m-d'), $dateOf($next)['work_date'], 'sent: the new one is today\'s too');

    // a historical case: the first receipt is the old day, the next is today
    $old = fx_case(fx_folder(fx_patient(), $doc));
    $db->update('cases', ['is_historical' => 1, 'historical_date' => '2026-09-10'], 'id = ?', [$old]);
    $first = $ci->getOrCreateActiveDraft($old, $doc);
    $t->assertSame('2026-09-10', $dateOf($first)['work_date'], 'historical: the first receipt is the old work day');
    $t->assertSame($first, $ci->getOrCreateActiveDraft($old, $doc), 'historical: and it is reused while open');
    $db->update('case_invoices', ['status' => 'sent', 'accounting_status' => 'sent'], 'id = ?', [$first]);
    $t->assertSame(date('Y-m-d'), $dateOf($ci->getOrCreateActiveDraft($old, $doc))['work_date'], 'historical: later work is today\'s');
})();
