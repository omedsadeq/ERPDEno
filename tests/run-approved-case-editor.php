<?php
// Standalone policy tests: no connection to the clinic database is made.
if (PHP_SAPI !== 'cli') { exit('CLI only'); }
require __DIR__ . '/TestCase.php';
$t = new TestCase();

// Load just the page's correction policy, without rendering or authenticating.
$source = file_get_contents(__DIR__ . '/../cases/view.php');
$start = strpos($source, 'function guard_billed_treatments(');
$end = strpos($source, 'function invoice_edit_allowed(', $start);
eval(substr($source, $start, $end - $start));

class EditorPolicyDb {
    public $invoice;
    public $total = 100;
    public $allocated = 0;
    public $plan = false;
    public $committed = false;
    public $rolledBack = false;
    public $original;
    public function __construct() {
        $this->invoice = [
            'id' => 7, 'case_id' => 3, 'accounting_status' => 'approved',
            'total_amount' => 100, 'discount_mode' => 'amount',
            'discount_amount' => 0, 'discount_value' => 0,
            'draft_received_amount' => 40, 'prior_paid_amount' => 10,
            'advance_paid_amount' => 5,
            'approved_by' => 2, 'approved_at' => '2026-09-01 10:00:00',
        ];
        $this->original = $this->invoice;
    }
    public function fetch($sql, $params) {
        if (strpos($sql, 'SUM(final_price)') !== false) return ['total' => $this->total];
        if (strpos($sql, 'installment_plans') !== false) return $this->plan ? ['id' => 1] : null;
        if (strpos($sql, 'payment_allocations') !== false) return ['amount' => $this->allocated];
        throw new RuntimeException('Unexpected query: ' . $sql);
    }
    public function fetchAll($sql, $params) {
        return [['invoice_id' => 7, 'accounting_status' => $this->invoice['accounting_status']]];
    }
    public function commit() { $this->committed = true; }
    public function rollback() { $this->rolledBack = true; $this->invoice = $this->original; }
}
class EditorPolicyInvoices {
    public $calls = 0;
    public function getById($id) { global $db; return $db->invoice; }
    public function recomputeTotal($id) {
        global $db;
        $this->calls++;
        $db->invoice['total_amount'] = $db->total;
    }
}
class EditorPolicyCases {
    public $calls = 0;
    public function updateCaseTotals($id) { $this->calls++; }
}
function case_view_fail($caseId, $message, $dialog = null) {
    global $db;
    $db->rollback();
    throw new RuntimeException($message);
}
function invoice_edit_allowed($isAdmin) { return $isAdmin; }
function invoice_locked_message() { return 'Invoice locked'; }
function editor_fixture() {
    global $db, $caseInvoice, $caseManager;
    $db = new EditorPolicyDb();
    $caseInvoice = new EditorPolicyInvoices();
    $caseManager = new EditorPolicyCases();
}
function editor_rejected() {
    try { finish_case_treatment_edit([7], 3); return false; }
    catch (RuntimeException $e) { return true; }
}

editor_fixture();
$canEditApprovedTreatments = false;
$blocked = false;
try { guard_billed_treatments($db, 3, [1], false); }
catch (RuntimeException $e) { $blocked = true; }
$t->assertTrue($blocked, 'approved work remains locked without permission');

editor_fixture();
$canEditApprovedTreatments = true;
$t->assertTrue(guard_billed_treatments($db, 3, [1], false) === [7], 'authorized accountant can correct approved work');
$db->invoice['accounting_status'] = 'sent';
$blocked = false;
try { guard_billed_treatments($db, 3, [1], false); }
catch (RuntimeException $e) { $blocked = true; }
$t->assertTrue($blocked, 'approved edit permission does not unlock sent invoices');

editor_fixture();
$db->total = 120;
finish_case_treatment_edit([7, 7], 3);
$t->assertTrue($db->committed && !$db->rolledBack, 'valid correction commits');
$t->assertTrue($caseInvoice->calls === 1 && $caseManager->calls === 1, 'invoice and case totals refreshed once');
$t->assertTrue($db->invoice['accounting_status'] === 'approved'
    && $db->invoice['approved_by'] === 2
    && $db->invoice['approved_at'] === '2026-09-01 10:00:00', 'approval identity preserved');

editor_fixture();
$db->total = 70;
$db->allocated = 20; // 55 received + 20 allocated exceeds the corrected net.
$t->assertTrue(editor_rejected() && $db->rolledBack && !$db->committed, 'received and reserved money cannot exceed corrected net');
$t->assertTrue($caseInvoice->calls === 0, 'invalid reduction does not recompute or commit');

editor_fixture();
$db->total = 110;
$db->plan = true;
$t->assertTrue(editor_rejected() && $db->rolledBack, 'active installment plan prevents price change');

editor_fixture();
$db->plan = true;
finish_case_treatment_edit([7], 3);
$t->assertTrue($db->committed, 'clinical correction with unchanged total remains possible with active plan');

editor_fixture();
$db->total = 80;
$db->invoice['discount_mode'] = 'percent';
$db->invoice['discount_value'] = 50;
$t->assertTrue(editor_rejected(), 'percentage discount included when checking paid floor');

editor_fixture();
$db->total = 75;
$db->allocated = 20;
finish_case_treatment_edit([7], 3);
$t->assertTrue($db->committed, 'corrected net exactly equal to received plus reserved is allowed');

class Settings {
    public static function allowEditSentInvoice() { return false; }
}
$paneSource = file_get_contents(__DIR__ . '/../cases/partials/invoice_pane.php');
$paneStart = strpos($paneSource, '$tid      =');
$paneEnd = strpos($paneSource, '$panePendingPlan =', $paneStart);
$panePolicy = substr($paneSource, $paneStart, $paneEnd - $paneStart);
$tab = ['id' => 7, 'status' => 'processed', 'accounting_status' => 'approved'];
$case = ['status' => 'open'];
$isAdmin = false;
$canEditApprovedTreatments = true;
eval($panePolicy);
$t->assertTrue($editable, 'approved treatment controls visible to authorized accountant');
$canEditApprovedTreatments = false;
eval($panePolicy);
$t->assertTrue(!$editable, 'approved treatment controls hidden when permission disabled');
$canEditApprovedTreatments = true;
$case['status'] = 'closed';
eval($panePolicy);
$t->assertTrue(!$editable, 'closed case must be reopened before editing');

echo "{$t->assertions} assertions, {$t->failures} failures\n";
exit($t->failures ? 1 : 0);
