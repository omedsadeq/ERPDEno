<?php
require_once __DIR__ . '/CashboxLedger.php';
/**
 * ApprovalReversal — کردنەوە بۆ دەستکاری و سڕینەوەی تۆمارە پەسەندکراوەکان
 *
 * The write side of the Approval Center's پەسەندکراو tab. Everything already
 * approved can be taken back two ways, and BOTH of them undo the money first:
 *
 *   reopen()  کردنەوە بۆ دەستکاری — the record returns to the state it was in
 *             just before the accountant decided on it, so it lands back in
 *             «هەموو داواکاریەکان» where the existing edit + approve forms work
 *             on it. Nothing is lost; it must be decided again.
 *             NOT for invoices: an approved invoice is edited in place and
 *             stays approved (CaseInvoice::editApproved) — see applyInvoice().
 *   remove()  سڕینەوە — the record is closed out (cancelled/refunded) and its
 *             money is reversed. No row is ever hard-deleted from a financial
 *             table: a cancelled document stays readable, it simply stops
 *             counting everywhere.
 *
 * "Reverse the money" is not one operation — each source books its cash in its
 * own place, so each has to be undone in that same place, through the service
 * that owns it wherever one exists (Payment::refund for a receipt,
 * PatientAdvance for ئامانەت, XrayManager's workflow for an X-ray). What every
 * kind's reversal lands on is the same rule the reports already follow:
 *
 *   - accountant/financial-summary.php counts chair cash from invoices with
 *     accounting_status='approved', so 'cancelled' removes it;
 *   - it counts receipts from payments with status='approved', so 'refunded'
 *     removes them;
 *   - it counts X-ray cash from xray_requests.payment_status='paid' and X-ray
 *     debt collection from xray_debt_payments, so clearing those removes it;
 *   - AccountingLedger / PatientLedger / v_case_financial_summary all skip a
 *     cancelled invoice and a refunded payment.
 *
 * preview() answers the question the accountant has to be able to ask BEFORE
 * pressing either button: exactly which transactions am I about to reverse.
 * The dialog in the Approval Center shows nothing but what this returns.
 *
 * Authorization is the caller's job — api/finance-actions.php gates the whole
 * file to accountant/admin. What this class does enforce is the per-record
 * rules: the admin lock on approved invoices (Settings::allowEditApprovedInvoice),
 * an active instalment plan blocking its invoice, and the optimistic status
 * guard on every write.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/AuditLog.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/CaseInvoice.php';
require_once __DIR__ . '/CaseManager.php';
require_once __DIR__ . '/Payment.php';
require_once __DIR__ . '/Installment.php';
require_once __DIR__ . '/PatientAdvance.php';

class ApprovalReversal
{
    /** Sources that can be reopened/removed. ژووری چاوەڕوانی has no decision. */
    public const KINDS = ['invoice', 'archwork', 'payment', 'installment', 'xray'];

    public const MODE_REOPEN = 'reopen';
    public const MODE_REMOVE = 'remove';

    /** Marker ArchWorkReceipt::approve() puts in every row its money creates. */
    private const ARCH_NOTE_PREFIX = 'وەسڵی کارە ئەنجامدراوەکان ';

    /** Marker Installment::activatePlan() puts on a down-payment receipt. */
    private const DOWN_PAYMENT_NOTE = 'پارەی پێشەکی - پلانی قیست';

    /**
     * Kinds reopen() no longer applies to. Sending an approved record back to
     * awaiting review dropped it out of the approved list and replaced its
     * approval date when it was granted again, so these are corrected IN PLACE
     * instead and stay approved: CaseInvoice::editApproved,
     * Payment::editApproved, Installment::editApprovedPlan and
     * XrayManager::editApprovedSettlement, each behind the «تەعدیل» button in
     * its Approval Center window. Their reopen branches below are therefore
     * unreachable; وەسڵی کارە ئەنجامدراوەکان (archwork) still reopens.
     */
    public const EDIT_IN_PLACE_KINDS = ['invoice', 'payment', 'installment', 'xray'];

    /** What a page still running the old script reads when it tries. */
    private const EDIT_IN_PLACE_MESSAGE = 'تۆماری پەسەندکراو ناگەڕێتەوە بۆ «چاوەڕوان». پەڕەکە نوێ بکەرەوە (F5)، '
        . 'پاشان تۆمارەکە بکەرەوە و دوگمەی «تەعدیل» دابگرە — پەسەندکراو دەمێنێتەوە و بەروارەکەی ناگۆڕێت';

    /** Kept for applyInvoice()'s own guard. */
    private const INVOICE_EDIT_IN_PLACE = self::EDIT_IN_PLACE_MESSAGE;

    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ================================================================
    // Preview — «what will this reverse?»
    // ================================================================

    /**
     * @return array ['success'=>bool,'message'?,'kind','id','number','patient_name',
     *   'status',
     *   'reopen'=>['allowed','message','note','reverses_cash'],
     *   'remove'=>['allowed','message','note','reverses_cash'],
     *   'lines'=>[['label','amount','note','effect']], 'cash_total'=>float,
     *   'warnings'=>string[]]
     *   effect: cash_out | debt_up | debt_down | doc
     *
     *   reverses_cash says whether THAT button actually takes money back out of
     *   the cash reports, so the dialog only claims the cash_total where it is
     *   true. Reopening an invoice is the one case where it is not: the receipt
     *   goes back to awaiting review with every payment still attached.
     */
    public function preview(string $kind, int $id): array
    {
        if (!in_array($kind, self::KINDS, true)) {
            return ['success' => false, 'message' => 'جۆرێکی نەزانراو'];
        }
        switch ($kind) {
            case 'invoice':     $preview = $this->previewInvoice($id); break;
            case 'archwork':    $preview = $this->previewArchWork($id); break;
            case 'payment':     $preview = $this->previewPayment($id); break;
            case 'installment': $preview = $this->previewInstallment($id); break;
            case 'xray':        $preview = $this->previewXray($id); break;
            default:            return ['success' => false, 'message' => 'جۆرێکی نەزانراو'];
        }
        // Corrected in place instead — never offer the reopen button for them.
        if (!empty($preview['success']) && in_array($kind, self::EDIT_IN_PLACE_KINDS, true)) {
            $preview['reopen'] = [
                'allowed'       => false,
                'message'       => self::EDIT_IN_PLACE_MESSAGE,
                'note'          => '',
                'reverses_cash' => false,
            ];
        }
        return $preview;
    }

    // ================================================================
    // Write entry points
    // ================================================================

    /**
     * کردنەوە بۆ دەستکاری — back to the pre-decision state. Only archwork
     * still goes this way; every EDIT_IN_PLACE_KINDS record is refused and
     * is corrected in place instead (see the constant).
     */
    public function reopen(string $kind, int $id, int $actorId, string $reason = ''): array
    {
        if (in_array($kind, self::EDIT_IN_PLACE_KINDS, true)) {
            return ['success' => false, 'message' => self::EDIT_IN_PLACE_MESSAGE];
        }
        return $this->dispatch(self::MODE_REOPEN, $kind, $id, $actorId, trim($reason));
    }

    /** سڕینەوە — reverse the money and close the record out. */
    public function remove(string $kind, int $id, int $actorId, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            return ['success' => false, 'message' => 'هۆکاری سڕینەوە پێویستە'];
        }
        return $this->dispatch(self::MODE_REMOVE, $kind, $id, $actorId, $reason);
    }

    private function dispatch(string $mode, string $kind, int $id, int $actorId, string $reason): array
    {
        if (!in_array($kind, self::KINDS, true)) {
            return ['success' => false, 'message' => 'جۆرێکی نەزانراو'];
        }
        if ($id <= 0) {
            return ['success' => false, 'message' => 'تۆمار دیاری نەکراوە'];
        }
        try {
            switch ($kind) {
                case 'invoice':     return $this->applyInvoice($mode, $id, $actorId, $reason);
                case 'archwork':    return $this->applyArchWork($mode, $id, $actorId, $reason);
                case 'payment':     return $this->applyPayment($mode, $id, $actorId, $reason);
                case 'installment': return $this->applyInstallment($mode, $id, $actorId, $reason);
                case 'xray':        return $this->applyXray($mode, $id, $actorId, $reason);
            }
        } catch (Throwable $e) {
            // Domain messages are already Kurdish and safe to show; anything
            // else (a PDOException carries SQL detail) is logged instead.
            if ($e instanceof InvalidArgumentException || ($e instanceof RuntimeException && !($e instanceof PDOException))) {
                return ['success' => false, 'message' => $e->getMessage()];
            }
            error_log('ApprovalReversal ' . $mode . ' ' . $kind . '#' . $id . ': ' . $e->getMessage());
            return ['success' => false, 'message' => 'هەڵەیەک ڕوویدا، دووبارە هەوڵبدەرەوە'];
        }
        return ['success' => false, 'message' => 'جۆرێکی نەزانراو'];
    }

    // ================================================================
    // Shared helpers
    // ================================================================

    /** The two rebuilds every money move on a case owes its readers. */
    private function rebuildCase(int $caseId, int $actorId): void
    {
        if ($caseId <= 0) {
            return;
        }
        try { (new CaseInvoice())->syncStatuses($caseId, $actorId); }
        catch (Throwable $e) { error_log('ApprovalReversal invoice sync failed: ' . $e->getMessage()); }
        try { (new CaseManager())->updateCaseTotals($caseId); }
        catch (Throwable $e) { error_log('ApprovalReversal case totals failed: ' . $e->getMessage()); }
    }

    private static function line(string $label, $amount, string $effect, string $note = ''): array
    {
        return [
            'label'  => $label,
            'amount' => $amount === null ? null : round((float)$amount, 2),
            'note'   => $note,
            'effect' => $effect,
        ];
    }

    /** Sum of the lines that take money back out of the cash/income reports. */
    private static function cashTotal(array $lines): float
    {
        $total = 0.0;
        foreach ($lines as $l) {
            if ($l['effect'] === 'cash_out' && $l['amount'] !== null) {
                $total += (float)$l['amount'];
            }
        }
        return round($total, 2);
    }

    /** Reverse one approved receipt through its owning service. */
    private function refundPayment(int $paymentId, string $reason, int $actorId): void
    {
        $result = (new Payment())->refund($paymentId, $reason, $actorId);
        if (empty($result['success'])) {
            throw new RuntimeException($result['message'] ?? 'گەڕاندنەوەی قەبزی پارەدان سەرنەکەوت');
        }
    }

    /**
     * The approved receipts that put money on ONE invoice, each with how much
     * of the receipt sits on this invoice and how much on others — which is
     * what decides whether the whole receipt is reversed or only its line here.
     */
    private function invoicePaymentLinks(int $invoiceId): array
    {
        return $this->db->fetchAll(
            "SELECT p.id, p.payment_number, p.amount, p.payment_type, p.payment_method, p.case_id, p.notes,
                    pa.id AS allocation_id, pa.allocated_amount,
                    (SELECT COUNT(*) FROM payment_allocations pa2 WHERE pa2.payment_id = p.id) AS alloc_count
             FROM payment_allocations pa
             JOIN payments p ON p.id = pa.payment_id
             WHERE pa.invoice_id = :inv AND p.status = 'approved'
             ORDER BY p.payment_date, p.id",
            ['inv' => $invoiceId]
        );
    }

    /**
     * Undo the receipts on one invoice: a receipt that lives only on this
     * invoice is reversed in full, one that also covers other invoices keeps
     * its other lines and loses only this one (its money then reads as
     * unallocated on the case, which is the truth — it was collected, it just
     * no longer settles a cancelled document).
     *
     * @return float total money taken off this invoice
     */
    private function releaseInvoicePayments(int $invoiceId, string $reason, int $actorId): float
    {
        $moved = 0.0;
        foreach ($this->invoicePaymentLinks($invoiceId) as $link) {
            $amount = (float)$link['allocated_amount'];
            if ((int)$link['alloc_count'] <= 1) {
                $this->refundPayment((int)$link['id'], $reason, $actorId);
            } else {
                if ($link['payment_method'] === 'advance') {
                    throw new DomainException('ئەم قەبزە لە ئامانەتەوە چەند وەسڵێکی داوە؛ سەرەتا قەبزەکە هەڵبوەشێنەوە');
                }
                $this->db->delete('payment_allocations', 'id = :id', ['id' => (int)$link['allocation_id']]);
                AuditLog::log('payment_allocation_reversed', 'payment_allocations', (int)$link['allocation_id'], [
                    'payment_id' => (int)$link['id'],
                    'invoice_id' => $invoiceId,
                    'allocated_amount' => $amount,
                ], ['reason' => $reason], $actorId);
            }
            $moved += $amount;
        }
        return round($moved, 2);
    }

    // ================================================================
    // Invoice — پسووڵە
    // ================================================================

    private function invoiceRow(int $id)
    {
        return $this->db->fetch(
            "SELECT ci.*, p.id AS patient_id, p.full_name AS patient_name
             FROM case_invoices ci
             JOIN cases c ON c.id = ci.case_id
             JOIN patient_folders pf ON pf.id = c.folder_id
             JOIN patients p ON p.id = pf.patient_id
             WHERE ci.id = :id",
            ['id' => $id]
        );
    }

    /** Plans sitting on one invoice, whatever state they are in. */
    private function invoicePlans(int $invoiceId, bool $lock = false): array
    {
        return $this->db->fetchAll(
            "SELECT id, status, total_amount FROM installment_plans
             WHERE invoice_id = :inv AND status IN ('pending','active','completed')"
             . ($lock ? ' FOR UPDATE' : ''),
            ['inv' => $invoiceId]
        );
    }

    private function previewInvoice(int $id): array
    {
        $inv = $this->invoiceRow($id);
        if (!$inv) {
            return ['success' => false, 'message' => 'وەسڵ نەدۆزرایەوە'];
        }
        if ($inv['accounting_status'] !== 'approved') {
            return ['success' => false, 'message' => 'تەنها وەسڵی پەسەندکراو دەکرێتەوە یان دەسڕدرێتەوە'];
        }

        $net      = max(0, (float)$inv['total_amount'] - (float)$inv['discount_amount']);
        $chair    = min($net, max(0, (float)($inv['draft_received_amount'] ?? 0)));
        $prior    = min(max(0, $net - $chair), max(0, (float)($inv['prior_paid_amount'] ?? 0)));
        $advance  = max(0, (float)($inv['advance_paid_amount'] ?? 0));

        $lines = [];
        $lines[] = self::line('کۆی وەسڵ (دوای داشکاندن)', $net, 'debt_down', 'بارەکە لەسەر نەخۆش لادەچێت');
        if ($chair > 0.009) {
            $lines[] = self::line('نەقدی لای دکتۆر — ناو وەسڵ', $chair, 'cash_out',
                'لە ڕاپۆرتی پارەی وەرگیراوی ئەو ڕۆژە لادەچێت');
        }
        if ($prior > 0.009) {
            $lines[] = self::line('دراوی پێشوو (کەیسی کۆن)', $prior, 'doc',
                'لە هیچ ڕاپۆرتی نەقدی نەبووە — تەنها قەرز کەم کردووە');
        }
        foreach ($this->invoicePaymentLinks($id) as $link) {
            $whole = (int)$link['alloc_count'] <= 1;
            $lines[] = self::line(
                'قەبزی پارەدان ' . ($link['payment_number'] ?: ('#' . $link['id'])),
                (float)$link['allocated_amount'],
                'cash_out',
                $whole ? 'قەبزەکە بە تەواوی هەڵدەوەشێتەوە' : 'تەنها بەشی ئەم وەسڵە لادەبرێت، قەبزەکە دەمێنێت'
            );
        }
        if ($advance > 0.009) {
            $lines[] = self::line('ئامانەتی دانراو لەسەر ئەم وەسڵە', $advance, 'doc',
                'دەگەڕێتەوە بۆ باڵانسی ئامانەتی نەخۆش');
        }
        $treatments = (int)($this->db->fetch(
            "SELECT COUNT(*) AS c FROM case_treatments WHERE invoice_id = :inv AND status <> 'cancelled'",
            ['inv' => $id]
        )['c'] ?? 0);
        if ($treatments > 0) {
            $lines[] = self::line('چارەسەرەکانی سەر وەسڵ', null, 'doc',
                $treatments . ' چارەسەر دەگەڕێتەوە بۆ «بێ وەسڵ» و دکتۆر دەتوانێت دوبارە بنێرێت');
        }

        $warnings = [];
        $plans = $this->invoicePlans($id);
        $blockingPlan = null;
        foreach ($plans as $plan) {
            if (in_array($plan['status'], ['active', 'completed'], true)) {
                $blockingPlan = $plan;
            } else {
                $lines[] = self::line('پلانی قیستی چاوەڕوان P-' . $plan['id'], (float)$plan['total_amount'], 'doc',
                    'هەڵدەوەشێتەوە');
            }
        }

        $lockOpen = Settings::allowEditApprovedInvoice();
        $gateMsg = 'قوفڵی وەسڵی پەسەندکراو داخراوە — بەڕێوەبەر > ڕێکخستنەکان > '
            . '«دەستکاریکردن و سڕینەوەی وەسڵ دوای پەسەندکردن» چالاکی بکات';
        $planMsg = 'پلانی قیستی چالاک لەسەر ئەم وەسڵە هەیە — سەرەتا ئەو پلانە یەکلایی بکەرەوە';

        if ($blockingPlan) {
            $warnings[] = $planMsg;
        }

        return [
            'success'      => true,
            'kind'         => 'invoice',
            'id'           => $id,
            'number'       => $inv['invoice_no'],
            'patient_name' => $inv['patient_name'],
            'status'       => $inv['accounting_status'],
            // Never offered for invoices — see applyInvoice(). Kept in the
            // payload so an old dialog reads a clear refusal, not a crash.
            'reopen'       => [
                'allowed'       => false,
                'message'       => self::INVOICE_EDIT_IN_PLACE,
                'note'          => '',
                'reverses_cash' => false,
            ],
            'remove'       => [
                'allowed'       => $lockOpen && !$blockingPlan,
                'message'       => !$lockOpen ? $gateMsg : ($blockingPlan ? $planMsg : ''),
                'note'          => 'وەسڵەکە دەچێتە دۆخی «هەڵوەشاوە»: لە کۆی کەیس، کشف حساب و ڕاپۆرتەکان نامێنێت، '
                                   . 'پارەی پێوەبەستراو دەگەڕێتەوە و چارەسەرەکان ئازاد دەکرێن. تۆمارەکە نەسڕدراوە — '
                                   . 'وەک بەڵگەیەکی هەڵوەشاو دەمێنێت.',
                'reverses_cash' => true,
            ],
            'lines'        => $lines,
            'cash_total'   => self::cashTotal($lines),
            'warnings'     => $warnings,
        ];
    }

    private function applyInvoice(string $mode, int $id, int $actorId, string $reason): array
    {
        $head = $this->invoiceRow($id);
        if (!$head) {
            throw new RuntimeException('وەسڵ نەدۆزرایەوە');
        }
        $caseId = (int)$head['case_id'];

        // An approved invoice is never sent back to «چاوەڕوانی پەسەند»: it is
        // edited IN PLACE (CaseInvoice::editApproved, from the invoice's own
        // window) and stays approved with its original approval date. The
        // پەسەندکراو tab's pencil opens that window for invoices, so this
        // refusal only ever meets a page still running the old script.
        if ($mode === self::MODE_REOPEN) {
            throw new RuntimeException(self::INVOICE_EDIT_IN_PLACE);
        }

        if (!Settings::allowEditApprovedInvoice()) {
            throw new RuntimeException('قوفڵی وەسڵی پەسەندکراو داخراوە — بەڕێوەبەر > ڕێکخستنەکان > '
                . '«دەستکاریکردن و سڕینەوەی وەسڵ دوای پەسەندکردن» چالاکی بکات');
        }

        $this->db->beginTransaction();
        try {
            $inv = $this->db->fetch(
                "SELECT * FROM case_invoices WHERE id = :id FOR UPDATE",
                ['id' => $id]
            );
            if (!$inv || $inv['accounting_status'] !== 'approved') {
                throw new RuntimeException('دۆخی وەسڵەکە گۆڕاوە — پەڕەکە نوێ بکەرەوە');
            }
            if ($this->db->fetch("SELECT p.id FROM payments p WHERE p.case_id=? AND p.discount_amount>0
                AND p.status IN ('approved','pending') AND (
                    EXISTS(SELECT 1 FROM payment_allocations pa WHERE pa.payment_id=p.id AND pa.invoice_id=?)
                    OR EXISTS(SELECT 1 FROM payment_discount_allocations da WHERE da.payment_id=p.id AND da.invoice_id=?)) LIMIT 1", [$caseId, $id, $id])) {
                throw new DomainException('ئەم وەسڵە بە قەبزی داشکاندن بەستراوە؛ سەرەتا قەبزەکە بگەڕێنەوە یان داواکارییەکە ڕەت بکەرەوە');
            }

            // 1. Plans. An active/completed one owns figures derived from this
            //    receipt, so it has to be dealt with on its own first.
            foreach ($this->invoicePlans($id, true) as $plan) {
                if (in_array($plan['status'], ['active', 'completed'], true)) {
                    throw new RuntimeException('پلانی قیستی چالاک لەسەر ئەم وەسڵە هەیە — ناتوانرێت بسڕدرێتەوە');
                }
                $this->db->update('installment_plans', [
                    'status'        => 'cancelled',
                    'reject_reason' => 'وەسڵ سڕدرایەوە: ' . $reason,
                ], 'id = :w_id', ['w_id' => (int)$plan['id']]);
                AuditLog::log('installment_plan_cancelled_with_invoice', 'installment_plans', (int)$plan['id'],
                    ['status' => $plan['status']], ['status' => 'cancelled', 'reason' => $reason], $actorId);
            }

            // 2. Receipts booked against it.
            $released = $this->releaseInvoicePayments($id, 'وەسڵ سڕدرایەوە: ' . $reason, $actorId);

            // 3. ئامانەت goes back to the patient's balance — it is real cash
            //    they handed over and it may not stay spent on a cancelled bill.
            $advanceReleased = 0.0;
            if ((float)($inv['advance_paid_amount'] ?? 0) > 0.009) {
                $advanceReleased = (new PatientAdvance())->releaseAllocations($id, $actorId);
            }

            // 4. The work returns to «بێ وەسڵ» so the doctor can re-bill it.
            $this->db->query(
                "UPDATE case_treatments SET invoice_id = NULL WHERE invoice_id = :inv",
                ['inv' => $id]
            );

            // 5. The document itself. Never hard-deleted: 'cancelled' is the
            //    status every reader (AccountingLedger, PatientLedger,
            //    v_case_financial_summary, financial-summary.php) already skips.
            // `status` (the legacy draft/sent/processed display column) is
            // deliberately left alone: accounting_status='cancelled' is what
            // every reader keys off, and writing 'draft' there would make a
            // cancelled document look like the doctor's open tab.
            $now = date('Y-m-d H:i:s');
            $changes = [
                'accounting_status'   => 'cancelled',
                'approved_by'         => null,
                'approved_at'         => null,
                'processed_by'        => null,
                'processed_at'        => null,
                'returned_by'         => $actorId ?: null,
                'returned_at'         => $now,
                'return_reason'       => 'سڕدرایەوە لە ناوەندی پەسەندکردن: ' . $reason,
                'advance_paid_amount' => 0,
                'updated_at'          => $now,
            ];
            $stmt = $this->db->update('case_invoices', $changes,
                'id = :w_id AND accounting_status = :w_expected',
                ['w_id' => $id, 'w_expected' => 'approved']
            );
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('دۆخی وەسڵەکە گۆڕاوە — پەڕەکە نوێ بکەرەوە');
            }

            AuditLog::log('invoice_deleted_reversed', 'case_invoices', $id, $inv, [
                'accounting_status' => 'cancelled',
                'reason'            => $reason,
                'payments_released' => $released,
                'advance_released'  => $advanceReleased,
            ], $actorId);
            CashboxLedger::capture('invoice', $id, $actorId, 'هەڵوەشاندنەوەی وەسڵ: ' . $reason);

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        $this->rebuildCase($caseId, $actorId);
        return ['success' => true, 'mode' => $mode, 'kind' => 'invoice', 'id' => $id, 'case_id' => $caseId];
    }

    // ================================================================
    // Arch work receipt — وەسڵی کارە ئەنجامدراوەکان
    // ================================================================

    private function archRow(int $id)
    {
        if (!$this->db->tableExists('arch_work_receipts')) {
            return null;
        }
        return $this->db->fetch(
            "SELECT r.*, p.id AS patient_id, p.full_name AS patient_name
             FROM arch_work_receipts r
             JOIN cases c ON c.id = r.case_id
             JOIN patient_folders pf ON pf.id = c.folder_id
             JOIN patients p ON p.id = pf.patient_id
             WHERE r.id = :id",
            ['id' => $id]
        );
    }

    /**
     * Everything one arch receipt's collection wrote. It was booked through
     * DebtPayment::distributePayment(), which spreads the money over whatever
     * the patient owed — case receipts, X-ray debts, or the credit balance when
     * they overpaid — and stamps every row it writes with the receipt number
     * (ArchWorkReceipt::approve passes it as the note). That note is the only
     * link back, so it is what this follows.
     */
    private function archMoneyRows(array $receipt): array
    {
        $like = '%' . self::ARCH_NOTE_PREFIX . $receipt['receipt_no'] . '%';
        $out = ['payments' => [], 'xray' => [], 'credits' => []];

        $out['payments'] = $this->db->fetchAll(
            "SELECT id, payment_number, amount, case_id, payment_type
             FROM payments
             WHERE status = 'approved' AND notes LIKE :note
             ORDER BY payment_date, id",
            ['note' => $like]
        );

        if ($this->db->tableExists('xray_debt_payments')) {
            $out['xray'] = $this->db->fetchAll(
                "SELECT xdp.id, xdp.debt_id, xdp.amount,
                        pxd.request_id, pxd.amount AS debt_amount, pxd.remaining_amount, pxd.status AS debt_status,
                        xr.request_number
                 FROM xray_debt_payments xdp
                 JOIN patient_xray_debts pxd ON pxd.id = xdp.debt_id
                 LEFT JOIN xray_requests xr ON xr.id = pxd.request_id
                 WHERE xdp.notes LIKE :note
                 ORDER BY xdp.id",
                ['note' => $like]
            );
        }

        if ($this->db->tableExists('patient_credit_logs')) {
            $out['credits'] = $this->db->fetchAll(
                "SELECT id, patient_id, amount
                 FROM patient_credit_logs
                 WHERE type = 'credit' AND amount > 0 AND description LIKE :note
                 ORDER BY id",
                ['note' => $like]
            );
        }

        return $out;
    }

    private function previewArchWork(int $id): array
    {
        $receipt = $this->archRow($id);
        if (!$receipt) {
            return ['success' => false, 'message' => 'وەسڵ نەدۆزرایەوە'];
        }
        if ($receipt['status'] !== 'approved') {
            return ['success' => false, 'message' => 'تەنها وەسڵی پەسەندکراو دەکرێتەوە یان دەسڕدرێتەوە'];
        }

        $money = $this->archMoneyRows($receipt);
        $lines = [];
        foreach ($money['payments'] as $p) {
            $lines[] = self::line('قەبزی پارەدان ' . ($p['payment_number'] ?: ('#' . $p['id'])),
                (float)$p['amount'], 'cash_out', 'هەڵدەوەشێتەوە و قەرزی کەیس دەگەڕێتەوە');
        }
        foreach ($money['xray'] as $x) {
            $lines[] = self::line('پارەدانی قەرزی ئێکسڕەی ' . ($x['request_number'] ?: ('#' . $x['request_id'])),
                (float)$x['amount'], 'cash_out', 'لادەبرێت و قەرزی ئێکسڕەی دەگەڕێتەوە');
        }
        foreach ($money['credits'] as $c) {
            $lines[] = self::line('باڵانسی زیادە', (float)$c['amount'], 'cash_out',
                'لە باڵانسی نەخۆش لادەبرێت');
        }
        $paid = (float)$receipt['paid_amount'];
        if ($paid > 0.009 && !$lines) {
            // Nothing matched the note — better to say so than to quietly
            // reverse the document and leave the cash standing.
            $lines[] = self::line('پارەی وەرگیراو', $paid, 'cash_out', 'تۆمارەکەی نەدۆزرایەوە — بە دەست پێویستە');
        }
        $works = (int)($this->db->fetch(
            "SELECT COUNT(*) AS c FROM arch_procedure_logs WHERE work_receipt_id = :rid",
            ['rid' => $id]
        )['c'] ?? 0);

        $warnings = [];
        if ($paid > 0.009 && !$money['payments'] && !$money['xray'] && !$money['credits']) {
            $warnings[] = 'تۆماری پارەدانی ئەم وەسڵە بەپێی ژمارەی وەسڵ نەدۆزرایەوە — دوای سڕینەوە بە دەست پشکنین بکە';
        }

        $reopenNote = 'وەسڵەکە دەگەڕێتەوە بۆ «چاوەڕوانی پەسەند» لەگەڵ کارەکانی، و پارەی وەرگیراوی '
            . 'هەڵدەوەشێتەوە — دووبارە پەسەندکردن، پارەی نوێ وەردەگرێت.';
        $removeLines = $lines;
        if ($works > 0) {
            $removeLines[] = self::line('کارە ئەنجامدراوەکان', null, 'doc',
                $works . ' کار ئازاد دەکرێت و دکتۆر دەتوانێت لە وەسڵێکی نوێ بنێرێتەوە');
        }

        return [
            'success'      => true,
            'kind'         => 'archwork',
            'id'           => $id,
            'number'       => $receipt['receipt_no'],
            'patient_name' => $receipt['patient_name'],
            'status'       => $receipt['status'],
            'reopen'       => ['allowed' => true, 'message' => '', 'note' => $reopenNote, 'reverses_cash' => true],
            'remove'       => [
                'allowed'       => true,
                'message'       => '',
                'note'          => 'وەسڵەکە هەڵدەوەشێتەوە، پارەی وەرگیراوی دەگەڕێتەوە بۆ قەرزی نەخۆش، '
                                   . 'و کارەکان ئازاد دەکرێن بۆ ئەوەی دکتۆر لە وەسڵێکی نوێ بنێرێتەوە.',
                'reverses_cash' => true,
            ],
            'lines'        => $removeLines,
            'cash_total'   => self::cashTotal($lines),
            'warnings'     => $warnings,
        ];
    }

    /** Undo one xray_debt_payments row: the debt gets its remainder back. */
    private function reverseXrayDebtPayment(array $row, string $reason, int $actorId): void
    {
        $debt = $this->db->fetch(
            "SELECT * FROM patient_xray_debts WHERE id = :id FOR UPDATE",
            ['id' => (int)$row['debt_id']]
        );
        if (!$debt) {
            return;
        }
        $amount    = round((float)$row['amount'], 2);
        $remaining = round((float)$debt['remaining_amount'] + $amount, 2);
        $total     = round((float)$debt['amount'], 2);
        if ($remaining > $total) {
            $remaining = $total;
        }
        $status = $remaining >= $total - 0.009 ? 'pending' : 'partial';

        $this->db->delete('xray_debt_payments', 'id = :id', ['id' => (int)$row['id']]);
        $this->db->update('patient_xray_debts', [
            'remaining_amount' => $remaining,
            'status'           => $status,
        ], 'id = :w_id', ['w_id' => (int)$debt['id']]);

        // Giving the remainder back always reopens the debt, so a request that
        // XrayManager::markDebtSettled() flipped to 'paid' when this collection
        // closed it is no longer settled.
        $requestId = (int)$debt['request_id'];
        if ($requestId > 0) {
            $request = $this->db->fetch(
                "SELECT payment_status FROM xray_requests WHERE id = :id",
                ['id' => $requestId]
            );
            if ($request && $request['payment_status'] === 'paid') {
                $this->db->update('xray_requests', [
                    'payment_status'  => 'debt_approved',
                    'paid_at'         => null,
                    'paid_by'         => null,
                    'last_updated_at' => date('Y-m-d H:i:s'),
                ], 'id = :w_id', ['w_id' => $requestId]);
            }
        }

        AuditLog::log('xray_debt_payment_reversed', 'xray_debt_payments', (int)$row['id'], $row, [
            'reason'               => $reason,
            'debt_remaining_after' => $remaining,
        ], $actorId);
        CashboxLedger::capture('xray', $requestId, $actorId, 'گەڕاندنەوەی نەقدی قەرزی ئێکسڕەی: ' . $reason);
    }

    /** Take a credit-balance top-up back off the patient's balance. */
    private function reverseCreditLog(array $log, string $reason, int $actorId): void
    {
        $patientId = (int)$log['patient_id'];
        $amount    = round((float)$log['amount'], 2);
        if ($patientId <= 0 || $amount <= 0.009) {
            return;
        }
        $credit = $this->db->fetch(
            "SELECT * FROM patient_credits WHERE patient_id = :pid FOR UPDATE",
            ['pid' => $patientId]
        );
        $balance = $credit ? (float)$credit['balance'] : 0.0;
        $take    = min($balance, $amount);
        if ($credit) {
            $this->db->update('patient_credits', [
                'balance'    => round($balance - $take, 2),
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'patient_id = :w_pid', ['w_pid' => $patientId]);
        }
        // Same shape DebtPayment::useCredit() writes when credit is spent.
        $this->db->insert('patient_credit_logs', [
            'patient_id'   => $patientId,
            'amount'       => -$take,
            'type'         => 'debit',
            'description'  => 'هەڵوەشاندنەوە: ' . $reason,
            'performed_by' => $actorId ?: null,
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
        AuditLog::log('patient_credit_reversed', 'patient_credit_logs', (int)$log['id'], $log,
            ['reversed' => $take, 'reason' => $reason], $actorId);
    }

    private function applyArchWork(string $mode, int $id, int $actorId, string $reason): array
    {
        $receipt = $this->archRow($id);
        if (!$receipt) {
            throw new RuntimeException('وەسڵ نەدۆزرایەوە');
        }
        if ($receipt['status'] !== 'approved') {
            throw new RuntimeException('تەنها وەسڵی پەسەندکراو دەکرێتەوە یان دەسڕدرێتەوە');
        }
        $caseId = (int)$receipt['case_id'];
        $note   = ($mode === self::MODE_REMOVE ? 'وەسڵی کارەکان سڕدرایەوە: ' : 'وەسڵی کارەکان کرایەوە: ') . $reason;

        $this->db->beginTransaction();
        try {
            $locked = $this->db->fetch(
                "SELECT * FROM arch_work_receipts WHERE id = :id FOR UPDATE",
                ['id' => $id]
            );
            if (!$locked || $locked['status'] !== 'approved') {
                throw new RuntimeException('دۆخی وەسڵەکە گۆڕاوە — پەڕەکە نوێ بکەرەوە');
            }

            $money = $this->archMoneyRows($locked);
            foreach ($money['payments'] as $p) {
                $this->refundPayment((int)$p['id'], $note, $actorId);
            }
            foreach ($money['xray'] as $x) {
                $this->reverseXrayDebtPayment($x, $note, $actorId);
            }
            foreach ($money['credits'] as $c) {
                $this->reverseCreditLog($c, $note, $actorId);
            }
            // The master debt-collection row is history, not income (no report
            // reads it), so it is annotated rather than deleted.
            if (!empty($locked['payment_id']) && $this->db->tableExists('patient_debt_payments')) {
                $master = $this->db->fetch(
                    "SELECT id, notes FROM patient_debt_payments WHERE id = :id",
                    ['id' => (int)$locked['payment_id']]
                );
                if ($master) {
                    $this->db->update('patient_debt_payments', [
                        'notes' => trim((string)$master['notes']) . ' | ' . $note,
                    ], 'id = :w_id', ['w_id' => (int)$master['id']]);
                }
            }

            $now = date('Y-m-d H:i:s');
            $changes = [
                'paid_amount'         => 0,
                'payment_method'      => null,
                'payment_id'          => null,
                'patient_debt_before' => 0,
                'approved_by'         => null,
                'approved_at'         => null,
                'reversed_by'         => $actorId ?: null,
                'reversed_at'         => $now,
                'reversal_reason'     => $reason,
            ];
            if ($mode === self::MODE_REMOVE) {
                $changes['status']        = 'cancelled';
                $changes['return_reason'] = 'سڕدرایەوە: ' . $reason;
            } else {
                $changes['status'] = 'sent';
            }
            $stmt = $this->db->update('arch_work_receipts', $changes,
                'id = :w_id AND status = :w_expected',
                ['w_id' => $id, 'w_expected' => 'approved']
            );
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('دۆخی وەسڵەکە گۆڕاوە — پەڕەکە نوێ بکەرەوە');
            }

            // A cancelled receipt releases its works so the doctor can send
            // them again; a reopened one keeps them — it IS the same document
            // going back for another decision.
            if ($mode === self::MODE_REMOVE) {
                $this->db->query(
                    "UPDATE arch_procedure_logs SET work_receipt_id = NULL WHERE work_receipt_id = :rid",
                    ['rid' => $id]
                );
            }

            AuditLog::log(
                $mode === self::MODE_REMOVE ? 'arch_work_receipt_deleted' : 'arch_work_receipt_reopened',
                'arch_work_receipts', $id, $locked,
                $changes + ['reason' => $reason], $actorId
            );

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        $this->rebuildCase($caseId, $actorId);
        return ['success' => true, 'mode' => $mode, 'kind' => 'archwork', 'id' => $id, 'case_id' => $caseId];
    }

    // ================================================================
    // Payment receipt — قەبزی پارەدان
    // ================================================================

    /** The instalment schedule entry a receipt was applied through, if any. */
    private function paymentInstallmentLink(int $paymentId)
    {
        if (!$this->db->tableExists('installment_payments')) {
            return null;
        }
        return $this->db->fetch(
            "SELECT id, plan_id, amount FROM installment_payments WHERE payment_id = :pid",
            ['pid' => $paymentId]
        );
    }

    private function previewPayment(int $id): array
    {
        $payment = $this->db->fetch(
            "SELECT pay.*, p.full_name AS patient_name
             FROM payments pay
             JOIN cases c ON c.id = pay.case_id
             JOIN patient_folders pf ON pf.id = c.folder_id
             JOIN patients p ON p.id = pf.patient_id
             WHERE pay.id = :id",
            ['id' => $id]
        );
        if (!$payment) {
            return ['success' => false, 'message' => 'قەبز نەدۆزرایەوە'];
        }
        if ($payment['status'] !== 'approved') {
            return ['success' => false, 'message' => 'تەنها قەبزی پەسەندکراو دەکرێتەوە یان دەسڕدرێتەوە'];
        }

        $lines = [self::line('بڕی قەبز', (float)$payment['amount'], 'cash_out',
            'لە ڕاپۆرتی پارەی وەرگیراو لادەچێت و قەرزی نەخۆش دەگەڕێتەوە')];

        foreach ($this->db->fetchAll(
            "SELECT pa.allocated_amount, ci.invoice_no
             FROM payment_allocations pa JOIN case_invoices ci ON ci.id = pa.invoice_id
             WHERE pa.payment_id = :pid ORDER BY ci.work_date, ci.invoice_seq",
            ['pid' => $id]
        ) as $alloc) {
            $lines[] = self::line('دانرابوو لەسەر وەسڵی ' . $alloc['invoice_no'],
                (float)$alloc['allocated_amount'], 'debt_up', 'ئەو وەسڵە دەگەڕێتەوە بۆ «نەدراو»');
        }

        $warnings = [];
        $link = $this->paymentInstallmentLink($id);
        if ($link) {
            $lines[] = self::line('قیستی پلان P-' . $link['plan_id'], (float)$link['amount'], 'debt_up',
                'خشتەی قیست دووبارە دەژمێردرێتەوە');
        }
        if (strpos((string)$payment['notes'], self::ARCH_NOTE_PREFIX) !== false) {
            $warnings[] = 'ئەم قەبزە بە وەسڵی کارە ئەنجامدراوەکان بەستراوە — باشترە لەوێ دەستکاری بکرێت';
        }

        return [
            'success'      => true,
            'kind'         => 'payment',
            'id'           => $id,
            'number'       => $payment['payment_number'],
            'patient_name' => $payment['patient_name'],
            'status'       => $payment['status'],
            'reopen'       => [
                'allowed'       => true,
                'message'       => '',
                'note'          => 'قەبزەکە دەگەڕێتەوە بۆ «چاوەڕوان» و لە لیستی داواکارییەکان دەردەکەوێت، '
                                   . 'بۆ ئەوەی بڕەکەی بگۆڕدرێت و دووبارە پەسەند بکرێت. تا ئەوکاتە لە '
                                   . 'ڕاپۆرتی پارەی وەرگیراو نایەت.',
                'reverses_cash' => true,
            ],
            'remove'       => [
                'allowed'       => true,
                'message'       => '',
                'note'          => 'قەبزەکە دەچێتە دۆخی «هەڵوەشاوە» — لە ڕاپۆرتی پارەی وەرگیراو نامێنێت و '
                                   . 'قەرزی نەخۆش دەگەڕێتەوە. قەبزەکە خۆی وەک مێژوو دەمێنێت.',
                'reverses_cash' => true,
            ],
            'lines'        => $lines,
            'cash_total'   => self::cashTotal($lines),
            'warnings'     => $warnings,
        ];
    }

    private function applyPayment(string $mode, int $id, int $actorId, string $reason): array
    {
        $head = $this->db->fetch("SELECT case_id, status, payment_type FROM payments WHERE id = :id", ['id' => $id]);
        if (!$head) {
            throw new RuntimeException('قەبز نەدۆزرایەوە');
        }
        if ($head['status'] !== 'approved') {
            throw new RuntimeException('تەنها قەبزی پەسەندکراو دەکرێتەوە یان دەسڕدرێتەوە');
        }
        $caseId = (int)$head['case_id'];
        $planId = 0;

        if ($mode === self::MODE_REMOVE) {
            // The canonical reversal: the receipt and its allocations stay as
            // history and stop counting because the receipt is refunded.
            $link = $this->paymentInstallmentLink($id);
            $planId = $link ? (int)$link['plan_id'] : 0;
            $this->refundPayment($id, $reason, $actorId);
        } else {
            $this->db->beginTransaction();
            try {
                $payment = $this->db->fetch("SELECT * FROM payments WHERE id = :id FOR UPDATE", ['id' => $id]);
                if (!$payment || $payment['status'] !== 'approved') {
                    throw new RuntimeException('دۆخی قەبزەکە گۆڕاوە — پەڕەکە نوێ بکەرەوە');
                }
                // 'pending' is the pre-approval state: the allocations stay and
                // are counted as RESERVED rather than paid (AccountingLedger),
                // which is exactly where a request awaiting a decision sits.
                $changes = ['status' => 'pending', 'approved_by' => null];
                if (($payment['payment_method'] ?? '') === 'advance') {
                    require_once __DIR__ . '/PatientAdvance.php';
                    (new PatientAdvance())->releaseForPayment($id, $actorId);
                    $changes['payment_method'] = 'cash';
                }
                $stmt = $this->db->update('payments', $changes,
                    'id = :w_id AND status = :w_expected',
                    ['w_id' => $id, 'w_expected' => 'approved']
                );
                if ($stmt->rowCount() !== 1) {
                    throw new RuntimeException('دۆخی قەبزەکە گۆڕاوە — پەڕەکە نوێ بکەرەوە');
                }

                // The instalment schedule entry was written when the money was
                // confirmed; re-approving writes a fresh one (Payment::approve →
                // applyFlexibleToPlan), so this one must not be left behind.
                $link = $this->paymentInstallmentLink($id);
                if ($link) {
                    $planId = (int)$link['plan_id'];
                    $this->db->delete('installment_payments', 'id = :id', ['id' => (int)$link['id']]);
                    AuditLog::log('installment_entry_reversed', 'installment_payments', (int)$link['id'], $link,
                        ['reason' => $reason], $actorId);
                }

                AuditLog::log('payment_reopened_for_edit', 'payments', $id, $payment,
                    $changes + ['reason' => $reason], $actorId);
                CashboxLedger::capture('payment', $id, $actorId, 'کردنەوەی قەبز بۆ دەستکاری: ' . $reason);
                $this->db->commit();
            } catch (Throwable $e) {
                $this->db->rollback();
                throw $e;
            }
        }

        if ($planId > 0) {
            try { (new Installment())->recomputePlan($planId); }
            catch (Throwable $e) { error_log('ApprovalReversal plan recompute failed: ' . $e->getMessage()); }
        }
        $this->rebuildCase($caseId, $actorId);
        return ['success' => true, 'mode' => $mode, 'kind' => 'payment', 'id' => $id, 'case_id' => $caseId];
    }

    // ================================================================
    // Instalment plan — پلانی قیست
    // ================================================================

    /**
     * Every approved receipt this plan booked: the instalment collections
     * (installment_payments → payments) plus the down payment activatePlan()
     * wrote as its own receipt.
     *
     * The down payment carries no link back to the plan, so it is matched on
     * the note that path stamps on it, narrowed to this plan's case, its
     * invoice when it has one, and the day it was activated — a case can only
     * hold one active/pending plan at a time (Installment::hasBlockingPlan),
     * so that combination cannot reach an older plan's down payment.
     */
    private function planPayments(array $plan): array
    {
        $rows = [];
        $seen = [];

        if ($this->db->tableExists('installment_payments')) {
            foreach ($this->db->fetchAll(
                "SELECT ip.id AS entry_id, p.id, p.payment_number, p.amount
                 FROM installment_payments ip
                 JOIN payments p ON p.id = ip.payment_id
                 WHERE ip.plan_id = :plan AND p.status = 'approved'
                 ORDER BY p.payment_date, p.id",
                ['plan' => (int)$plan['id']]
            ) as $row) {
                $seen[(int)$row['id']] = true;
                $rows[] = $row + ['is_down' => false];
            }
        }

        $params = [
            'case_id' => (int)$plan['case_id'],
            'note'    => '%' . self::DOWN_PAYMENT_NOTE . '%',
        ];
        $narrow = '';
        if (!empty($plan['invoice_id'])) {
            $narrow .= ' AND (invoice_id IS NULL OR invoice_id = :invoice_id)';
            $params['invoice_id'] = (int)$plan['invoice_id'];
        }
        if (!empty($plan['approved_at'])) {
            $narrow .= ' AND DATE(payment_date) >= DATE(:approved_at)';
            $params['approved_at'] = $plan['approved_at'];
        }
        foreach ($this->db->fetchAll(
            "SELECT id, payment_number, amount FROM payments
             WHERE case_id = :case_id AND status = 'approved'
               AND payment_type = 'installment' AND notes LIKE :note{$narrow}
             ORDER BY payment_date, id",
            $params
        ) as $row) {
            if (isset($seen[(int)$row['id']])) {
                continue;
            }
            $rows[] = $row + ['entry_id' => null, 'is_down' => true];
        }

        return $rows;
    }

    private function previewInstallment(int $id): array
    {
        $plan = (new Installment())->getPlanById($id);
        if (!$plan) {
            return ['success' => false, 'message' => 'پلانی قیست نەدۆزرایەوە'];
        }
        if (!in_array($plan['status'], ['active', 'completed'], true)) {
            return ['success' => false, 'message' => 'تەنها پلانی چالاک یان تەواوبوو دەکرێتەوە یان دەسڕدرێتەوە'];
        }

        $lines = [];
        foreach ($this->planPayments($plan) as $p) {
            $lines[] = self::line(
                ($p['is_down'] ? 'پارەی پێشەکی ' : 'قەبزی قیست ') . ($p['payment_number'] ?: ('#' . $p['id'])),
                (float)$p['amount'], 'cash_out', 'هەڵدەوەشێتەوە و قەرز دەگەڕێتەوە'
            );
        }
        $scheduleCount = (int)($this->db->fetch(
            "SELECT COUNT(*) AS c FROM installment_schedule WHERE plan_id = :plan",
            ['plan' => $id]
        )['c'] ?? 0);
        if ($scheduleCount > 0) {
            $lines[] = self::line('خشتەی قیست', null, 'doc', $scheduleCount . ' قیست');
        }

        return [
            'success'      => true,
            'kind'         => 'installment',
            'id'           => $id,
            'number'       => 'P-' . $id,
            'patient_name' => $plan['patient_name'] ?? '',
            'status'       => $plan['status'],
            'reopen'       => [
                'allowed'       => true,
                'message'       => '',
                'note'          => 'پلانەکە دەگەڕێتەوە بۆ «چاوەڕوان»، خشتەکەی دەسڕدرێتەوە و هەموو قەبزەکانی '
                                   . 'هەڵدەوەشێنەوە، بۆ ئەوەی بە مەرجی نوێ دووبارە چالاک بکرێت.',
                'reverses_cash' => true,
            ],
            'remove'       => [
                'allowed'       => true,
                'message'       => '',
                'note'          => 'پلانەکە هەڵدەوەشێتەوە و هەموو قەبزەکانی — لەگەڵ پارەی پێشەکی — دەگەڕێنەوە، '
                                   . 'بۆیە قەرزی وەسڵ دەگەڕێتەوە وەک پێش پلان.',
                'reverses_cash' => true,
            ],
            'lines'        => $lines,
            'cash_total'   => self::cashTotal($lines),
            'warnings'     => [],
        ];
    }

    private function applyInstallment(string $mode, int $id, int $actorId, string $reason): array
    {
        $installment = new Installment();
        $plan = $installment->getPlanById($id);
        if (!$plan) {
            throw new RuntimeException('پلانی قیست نەدۆزرایەوە');
        }
        if (!in_array($plan['status'], ['active', 'completed'], true)) {
            throw new RuntimeException('تەنها پلانی چالاک یان تەواوبوو دەکرێتەوە یان دەسڕدرێتەوە');
        }
        $caseId = (int)$plan['case_id'];
        $note = ($mode === self::MODE_REMOVE ? 'پلانی قیست سڕدرایەوە: ' : 'پلانی قیست کرایەوە: ') . $reason;

        $this->db->beginTransaction();
        try {
            $locked = $this->db->fetch("SELECT * FROM installment_plans WHERE id = :id FOR UPDATE", ['id' => $id]);
            if (!$locked || !in_array($locked['status'], ['active', 'completed'], true)) {
                throw new RuntimeException('دۆخی پلانەکە گۆڕاوە — پەڕەکە نوێ بکەرەوە');
            }

            // Every receipt the plan booked is reversed, whichever way this goes:
            // the plan is no longer in force, so its collections cannot stay
            // counted against it.
            foreach ($this->planPayments($locked) as $p) {
                $this->refundPayment((int)$p['id'], $note, $actorId);
                if ($mode === self::MODE_REOPEN && !empty($p['entry_id'])) {
                    $this->db->delete('installment_payments', 'id = :id', ['id' => (int)$p['entry_id']]);
                }
            }

            if ($mode === self::MODE_REOPEN) {
                // buildSchedule() appends, so re-activating on top of the old
                // rows would double the schedule — it goes now, with the plan
                // returning to the figures the doctor proposed.
                $this->db->delete('installment_schedule', 'plan_id = :plan', ['plan' => $id]);
                // Back to the figures the doctor proposed: activatePlan() derives
                // the down payment from proposed_down_payment and rebuilds both
                // the schedule and remaining_amount when it is granted again.
                $changes = [
                    'status'           => 'pending',
                    'approved_by'      => null,
                    'approved_at'      => null,
                    'down_payment'     => 0,
                    'remaining_amount' => (float)$locked['total_amount'],
                ];
            } else {
                $changes = [
                    'status'        => 'cancelled',
                    'reject_reason' => 'سڕدرایەوە: ' . $reason,
                ];
            }
            $stmt = $this->db->update('installment_plans', $changes,
                'id = :w_id AND status = :w_expected',
                ['w_id' => $id, 'w_expected' => $locked['status']]
            );
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('دۆخی پلانەکە گۆڕاوە — پەڕەکە نوێ بکەرەوە');
            }

            AuditLog::log(
                $mode === self::MODE_REMOVE ? 'installment_plan_deleted' : 'installment_plan_reopened',
                'installment_plans', $id, $locked, $changes + ['reason' => $reason], $actorId
            );

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        // total_paid/credit_balance are derived, never asserted: a legacy entry
        // with no canonical receipt (migrated from the old system) is money that
        // really was collected and must keep counting.
        try { $installment->recomputePlan($id); }
        catch (Throwable $e) { error_log('ApprovalReversal plan recompute failed: ' . $e->getMessage()); }
        $this->rebuildCase($caseId, $actorId);
        return ['success' => true, 'mode' => $mode, 'kind' => 'installment', 'id' => $id, 'case_id' => $caseId];
    }

    // ================================================================
    // X-ray request — داواکاری ئێکسڕەی
    // ================================================================

    private function xrayRow(int $id)
    {
        return $this->db->fetch(
            "SELECT xr.*, p.full_name AS patient_name
             FROM xray_requests xr
             LEFT JOIN patients p ON p.id = xr.patient_id
             WHERE xr.id = :id",
            ['id' => $id]
        );
    }

    /** The debt row (and its collections) an approved X-ray created, if any. */
    private function xrayDebt(int $requestId)
    {
        if (!$this->db->tableExists('patient_xray_debts')) {
            return null;
        }
        $debt = $this->db->fetch(
            "SELECT * FROM patient_xray_debts WHERE request_id = :id",
            ['id' => $requestId]
        );
        if (!$debt) {
            return null;
        }
        $debt['payments'] = $this->db->tableExists('xray_debt_payments')
            ? $this->db->fetchAll(
                "SELECT * FROM xray_debt_payments WHERE debt_id = :id ORDER BY id",
                ['id' => (int)$debt['id']]
            )
            : [];
        return $debt;
    }

    private function previewXray(int $id): array
    {
        $request = $this->xrayRow($id);
        if (!$request) {
            return ['success' => false, 'message' => 'داواکاری ئێکسڕەی نەدۆزرایەوە'];
        }
        $decided = !empty($request['accountant_approved_at'])
            && !in_array($request['request_status'], ['pending_accountant', 'returned_to_accountant', 'cancelled'], true);
        if (!$decided) {
            return ['success' => false, 'message' => 'تەنها داواکاری پەسەندکراو دەکرێتەوە یان دەسڕدرێتەوە'];
        }

        $final = round((float)($request['final_amount'] ?: $request['amount']), 2);
        $debt  = $this->xrayDebt($id);
        $lines = [];

        if ($debt) {
            $collected = 0.0;
            foreach ($debt['payments'] as $p) {
                $collected += (float)$p['amount'];
            }
            if ($collected > 0.009) {
                $lines[] = self::line('پارەی وەرگیراو لەسەر قەرزی ئێکسڕەی', $collected, 'cash_out',
                    'لە ڕاپۆرتی وەرگیراوی ئێکسڕەی لادەچێت');
            }
            $lines[] = self::line('قەرزی ئێکسڕەی', (float)$debt['amount'], 'debt_down',
                'تۆماری قەرز لادەبرێت (ماوە: ' . number_format((float)$debt['remaining_amount']) . ')');
        } elseif ($request['payment_status'] === 'paid' && $final > 0.009) {
            $lines[] = self::line('پارەی نەقدی ئێکسڕەی', $final, 'cash_out',
                'لە ڕاپۆرتی وەرگیراوی ئێکسڕەی لادەچێت');
        }
        $lines[] = self::line('دۆخی داواکاری', null, 'doc',
            'دەگەڕێتەوە بۆ «چاوەڕوانی ژمێریار» و هەموو مۆرەکانی پەسەندکردن پاک دەکرێنەوە');

        $warnings = [];
        if (in_array($request['request_status'], ['in_xray', 'in_progress', 'completed'], true)) {
            $warnings[] = 'ئێکسڕەی ئەنجامدراوە یان لە پێشکردنە — گەڕاندنەوەی پارە کارەکە هەڵناوەشێنێتەوە';
        }

        return [
            'success'      => true,
            'kind'         => 'xray',
            'id'           => $id,
            'number'       => $request['request_number'] ?: ('X-' . $id),
            'patient_name' => $request['patient_name'] ?? '',
            'status'       => $request['request_status'],
            'reopen'       => [
                'allowed'       => true,
                'message'       => '',
                'note'          => 'داواکارییەکە دەگەڕێتەوە بۆ «چاوەڕوانی ژمێریار» و هەموو تۆمارە داراییەکانی '
                                   . 'لادەبرێن، بۆ ئەوەی سەرلەنوێ یەکلایی بکرێتەوە.',
                'reverses_cash' => true,
            ],
            'remove'       => [
                'allowed'       => true,
                'message'       => '',
                'note'          => 'داواکارییەکە دەچێتە دۆخی «هەڵوەشاوە» و هەموو تۆمارە داراییەکانی '
                                   . '(قەرزی ئێکسڕەی و پارەی وەرگیراو) لادەبرێن.',
                'reverses_cash' => true,
            ],
            'lines'        => $lines,
            'cash_total'   => self::cashTotal($lines),
            'warnings'     => $warnings,
        ];
    }

    private function applyXray(string $mode, int $id, int $actorId, string $reason): array
    {
        require_once __DIR__ . '/XrayManager.php';
        $manager = new XrayManager();
        $role = $_SESSION['role'] ?? (defined('ROLE_ACCOUNTANT') ? ROLE_ACCOUNTANT : 'accountant');
        $note = ($mode === self::MODE_REMOVE ? 'سڕدرایەوە لە ناوەندی پەسەندکردن: ' : 'کرایەوە بۆ دەستکاری: ') . $reason;

        $this->db->beginTransaction();
        try {
            $request = $this->db->fetch("SELECT * FROM xray_requests WHERE id = :id FOR UPDATE", ['id' => $id]);
            if (!$request) {
                throw new RuntimeException('داواکاری ئێکسڕەی نەدۆزرایەوە');
            }
            $decided = !empty($request['accountant_approved_at'])
                && !in_array($request['request_status'], ['pending_accountant', 'returned_to_accountant', 'cancelled'], true);
            if (!$decided) {
                throw new RuntimeException('دۆخی داواکارییەکە گۆڕاوە — پەڕەکە نوێ بکەرەوە');
            }

            // The debt row and every collection on it go, with their values
            // kept in the audit trail — this is what «return to default» means
            // for an X-ray: no debt, nothing collected, nothing booked as cash.
            $debt = $this->xrayDebt($id);
            if ($debt) {
                foreach ($debt['payments'] as $p) {
                    $this->db->delete('xray_debt_payments', 'id = :id', ['id' => (int)$p['id']]);
                    AuditLog::log('xray_debt_payment_reversed', 'xray_debt_payments', (int)$p['id'], $p,
                        ['reason' => $reason], $actorId);
                }
                $this->db->delete('patient_xray_debts', 'id = :id', ['id' => (int)$debt['id']]);
                AuditLog::log('xray_debt_reversed', 'patient_xray_debts', (int)$debt['id'], $debt,
                    ['reason' => $reason], $actorId);
            }

            // Back to the payment state the request had before the accountant
            // touched it — which depends on what the doctor asked for.
            $paymentType = (string)$request['payment_type'];
            if ($paymentType === 'dont_cash') {
                $paymentStatus = 'not_required';
            } elseif ($paymentType === 'debt') {
                $paymentStatus = 'debt_pending';
            } else {
                $paymentStatus = 'pending_payment';
            }

            $extra = [
                'accountant_id'          => null,
                'accountant_approved_at' => null,
                'paid_at'                => null,
                'paid_by'                => null,
                'settled_at'             => null,
                'settled_by'             => null,
                'xray_received_at'       => null,
                'refunded_at'            => null,
            ];
            if ($mode === self::MODE_REMOVE) {
                $extra['cancel_reason'] = $reason;
                $extra['cancelled_at']  = date('Y-m-d H:i:s');
                $target = 'cancelled';
                $action = 'accountant_deleted';
            } else {
                $extra['cancel_reason'] = null;
                $extra['cancelled_at']  = null;
                $extra['return_reason'] = null;
                $target = 'pending_accountant';
                $action = 'accountant_reopened';
            }

            $result = $manager->transitionWorkflow($id, $target, $paymentStatus, $action, $note, $actorId, $role, $extra);
            if (empty($result['success'])) {
                throw new RuntimeException($result['message'] ?? 'کردارەکە جێبەجێ نەکرا');
            }

            AuditLog::log(
                $mode === self::MODE_REMOVE ? 'xray_request_deleted' : 'xray_request_reopened',
                'xray_requests', $id, $request,
                ['request_status' => $target, 'payment_status' => $paymentStatus, 'reason' => $reason],
                $actorId
            );

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        return ['success' => true, 'mode' => $mode, 'kind' => 'xray', 'id' => $id];
    }
}
