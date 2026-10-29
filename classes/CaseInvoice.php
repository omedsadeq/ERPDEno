<?php
/**
 * CaseInvoice — per-visit receipts (وەسڵی سەردان) for a dental case.
 *
 * The case stays one case and the chart stays cumulative; invoices only
 * partition the MONEY side by visit day. Every treatment row already carries
 * its performed_date, so invoicing = grouping the not-yet-invoiced rows by
 * day, stamping them with an invoice_id, and numbering the batch
 * case_number-1, -2, ...
 *
 * The chair-side received figure is stored with the treatment invoice. Later
 * standalone receipts settle remaining debt through payment_allocations.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/AccountingLedger.php';
require_once __DIR__ . '/AuditLog.php';
require_once __DIR__ . '/CaseManager.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/CashboxLedger.php';

class CaseInvoice {
    private $db;
    private $ledger;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->ledger = new AccountingLedger();
    }

    /**
     * Open a new draft invoice — the doctor's "visit tab". Treatments entered
     * while it is active are stamped with its id.
     *
     * $doctorId — whose work and money this receipt is, picked by an admin or
     * accountant opening it for a doctor's patient. Left out, it is the user
     * when that is a doctor, else the case's doctor. The user is always kept
     * as created_by, so the receipt shows who really made it.
     *
     * @return array ['success' => bool, 'invoice_id' => int, 'invoice_no' => string]
     */
    public function createDraft($caseId, $workDate, $userId, $doctorId = null) {
        $caseId = (int)$caseId;
        $case = $this->db->fetch("SELECT id, case_number, status FROM cases WHERE id = :id", ['id' => $caseId]);
        if (!$case) {
            return ['success' => false, 'message' => 'کەیس نەدۆزرایەوە'];
        }
        if (($case['status'] ?? '') !== 'open') {
            return ['success' => false, 'message' => 'کەیسەکە داخراوە'];
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$workDate)) {
            $workDate = date('Y-m-d');
        }

        // The invoice's doctor: the one picked, else whoever opens it when that
        // is a doctor, else the case's own doctor — the cashbox and the doctor's
        // income both go by this column, and a non-doctor has no cashbox.
        $invoiceDoctor = null;
        if ((int)$doctorId > 0) {
            $invoiceDoctor = $this->db->fetch(
                "SELECT id FROM users WHERE id = :id AND role = 'doctor' AND is_active = 1",
                ['id' => (int)$doctorId]
            );
            if (!$invoiceDoctor) {
                return ['success' => false, 'message' => 'دکتۆر نەدۆزرایەوە'];
            }
        }
        $invoiceDoctor = $invoiceDoctor ?: $this->db->fetch(
            "SELECT u.id FROM users u WHERE u.id = :uid AND u.role = 'doctor'",
            ['uid' => (int)$userId]
        ) ?: $this->db->fetch(
            "SELECT pf.doctor_id AS id FROM cases c JOIN patient_folders pf ON pf.id = c.folder_id WHERE c.id = :cid",
            ['cid' => $caseId]
        );

        $this->db->beginTransaction();
        try {
            $seqRow = $this->db->fetch(
                "SELECT COALESCE(MAX(invoice_seq), 0) AS max_seq FROM case_invoices WHERE case_id = :cid FOR UPDATE",
                ['cid' => $caseId]
            );
            $seq = (int)($seqRow['max_seq'] ?? 0) + 1;
            $invoiceNo = $case['case_number'] . '-' . $seq;
            $invoiceId = $this->db->insert('case_invoices', [
                'case_id'      => $caseId,
                'doctor_id'    => !empty($invoiceDoctor['id']) ? (int)$invoiceDoctor['id'] : null,
                'created_by'   => $userId ? (int)$userId : null,
                'invoice_seq'  => $seq,
                'invoice_no'   => $invoiceNo,
                'work_date'    => $workDate,
                'total_amount' => 0,
                'status'       => 'draft',
                'accounting_status' => 'draft',
                'sent_by'      => $userId ? (int)$userId : null,
            ]);
            AuditLog::log('invoice_created', 'case_invoices', $invoiceId, null, [
                'case_id' => $caseId,
                'invoice_no' => $invoiceNo,
                'accounting_status' => 'draft',
                'doctor_id' => !empty($invoiceDoctor['id']) ? (int)$invoiceDoctor['id'] : null,
            ], $userId);
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
        return ['success' => true, 'invoice_id' => (int)$invoiceId, 'invoice_no' => $invoiceNo];
    }

    /**
     * Put a receipt under another doctor — an admin or accountant who filed it
     * for the wrong one. Only until it is approved: from then on its cash is in
     * that doctor's cashbox (CashboxLedger freezes the owner when it books).
     */
    public function setDoctor($caseId, $invoiceId, $doctorId, $actorId) {
        $invoice = $this->db->fetch(
            "SELECT id, doctor_id, accounting_status FROM case_invoices WHERE id = :id AND case_id = :cid",
            ['id' => (int)$invoiceId, 'cid' => (int)$caseId]
        );
        if (!$invoice) {
            return ['success' => false, 'message' => 'وەسڵ نەدۆزرایەوە'];
        }
        if (!in_array($invoice['accounting_status'], ['draft', 'returned', 'sent'], true)) {
            return ['success' => false, 'message' => 'دکتۆری وەسڵی پەسەندکراو ناگۆڕدرێت — پارەکەی چووەتە سندوقی دکتۆرەکە'];
        }
        $doctor = $this->db->fetch(
            "SELECT id FROM users WHERE id = :id AND role = 'doctor' AND is_active = 1",
            ['id' => (int)$doctorId]
        );
        if (!$doctor) {
            return ['success' => false, 'message' => 'دکتۆر نەدۆزرایەوە'];
        }
        if ((int)$invoice['doctor_id'] === (int)$doctorId) {
            return ['success' => true];
        }
        $this->db->update('case_invoices', ['doctor_id' => (int)$doctorId, 'updated_at' => date('Y-m-d H:i:s')],
            'id = :id', ['id' => (int)$invoiceId]);
        AuditLog::log('invoice_doctor_changed', 'case_invoices', (int)$invoiceId,
            ['doctor_id' => $invoice['doctor_id']], ['doctor_id' => (int)$doctorId], $actorId);
        return ['success' => true];
    }

    /**
     * Move a receipt to another day — the pencil beside its date on the visit
     * tab. Only while it is still a draft (or returned): once sent, its day is
     * part of the accountant's figures. Its treatments move with it, because
     * production reports read case_treatments.performed_date.
     */
    public function setWorkDate($caseId, $invoiceId, $workDate, $actorId) {
        $workDate = (string)$workDate;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate) || !strtotime($workDate)) {
            return ['success' => false, 'message' => 'بەروار هەڵەیە'];
        }
        if ($workDate > date('Y-m-d')) {
            return ['success' => false, 'message' => 'ناتوانرێت وەسڵ بۆ ڕۆژێکی داهاتوو دابنرێت'];
        }
        $invoice = $this->db->fetch(
            "SELECT id, work_date, accounting_status FROM case_invoices WHERE id = :id AND case_id = :cid",
            ['id' => (int)$invoiceId, 'cid' => (int)$caseId]
        );
        if (!$invoice) {
            return ['success' => false, 'message' => 'وەسڵ نەدۆزرایەوە'];
        }
        if (!in_array($invoice['accounting_status'], ['draft', 'returned'], true)) {
            return ['success' => false, 'message' => 'بەرواری وەسڵی نێردراو ناگۆڕدرێت'];
        }
        if ($invoice['work_date'] === $workDate) {
            return ['success' => true];
        }
        $this->db->beginTransaction();
        try {
            $this->db->update('case_invoices', ['work_date' => $workDate, 'updated_at' => date('Y-m-d H:i:s')],
                'id = :id', ['id' => (int)$invoiceId]);
            $this->db->query(
                "UPDATE case_treatments
                    SET performed_date = CONCAT(:d, ' ', TIME(COALESCE(performed_date, created_at)))
                  WHERE invoice_id = :id",
                ['d' => $workDate, 'id' => (int)$invoiceId]
            );
            AuditLog::log('invoice_date_changed', 'case_invoices', (int)$invoiceId,
                ['work_date' => $invoice['work_date']], ['work_date' => $workDate], $actorId);
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
        return ['success' => true];
    }

    /**
     * The draft a new treatment should be filed under when no tab is named:
     * today's open receipt, or a fresh one for today. Called whenever work is
     * entered without an explicit tab, so the doctor never has to think about
     * invoices. A receipt left unsent from an earlier day never takes today's
     * work — it would be dated, and counted, on that day.
     */
    public function getOrCreateActiveDraft($caseId, $userId) {
        // A historical case's first receipt belongs to the day the old work was
        // done, so it never lands in today's production figures — and while it
        // is open, the old work keeps going on it.
        $case = $this->db->fetch("SELECT is_historical, historical_date FROM cases WHERE id = :id", ['id' => (int)$caseId]);
        $oldDay = (!empty($case['is_historical']) && !empty($case['historical_date'])) ? $case['historical_date'] : null;
        $row = $this->db->fetch(
            "SELECT id FROM case_invoices WHERE case_id = :cid AND accounting_status IN ('draft','returned') AND work_date IN (:today, :old)
             ORDER BY (work_date = :today2) DESC, invoice_seq DESC LIMIT 1",
            ['cid' => (int)$caseId, 'today' => date('Y-m-d'), 'old' => $oldDay ?? date('Y-m-d'), 'today2' => date('Y-m-d')]
        );
        if ($row) {
            return (int)$row['id'];
        }
        $firstReceipt = !$this->db->fetch("SELECT id FROM case_invoices WHERE case_id = :cid AND accounting_status <> 'cancelled' LIMIT 1", ['cid' => (int)$caseId]);
        $workDate = ($oldDay !== null && $firstReceipt) ? $oldDay : date('Y-m-d');
        $made = $this->createDraft($caseId, $workDate, $userId);
        return !empty($made['success']) ? (int)$made['invoice_id'] : null;
    }

    /**
     * Verify a draft belongs to this case and is still editable.
     */
    public function getEditableDraft($caseId, $invoiceId) {
        return $this->db->fetch(
            "SELECT * FROM case_invoices
             WHERE id = :id AND case_id = :cid AND accounting_status IN ('draft','returned')",
            ['id' => (int)$invoiceId, 'cid' => (int)$caseId]
        );
    }

    /**
     * Save the chair-side received figure on the draft itself.
     *
     * This intentionally creates no payment, allocation, notification, or
     * payment-queue row. The figure is part of the treatment invoice document
     * and remains on that invoice when it is sent to and approved by accounting.
     */
    public function setDraftReceived($caseId, $invoiceId, $amount, $actorId = null) {
        $amount = round(max(0, (float)$amount), 2);
        $this->db->beginTransaction();
        try {
            $invoice = $this->db->fetch(
                "SELECT * FROM case_invoices
                 WHERE id=:id AND case_id=:case_id AND accounting_status IN ('draft','returned')
                 FOR UPDATE",
                ['id' => (int)$invoiceId, 'case_id' => (int)$caseId]
            );
            if (!$invoice) {
                throw new RuntimeException('تەنها وەسڵی درافت دەتوانرێت وەک درافت خەزن بکرێت');
            }
            $net = max(0, (float)$invoice['total_amount'] - (float)$invoice['discount_amount']);
            $prior = max(0, (float)($invoice['prior_paid_amount'] ?? 0));
            // ئامانەت — a morning counter receipt already put on this invoice
            // (PatientAdvance::allocate). Covers the bill exactly like the other
            // two, so it has to leave the room here as well.
            $advance = max(0, (float)($invoice['advance_paid_amount'] ?? 0));
            if ($amount > $net - $prior - $advance + 0.009) {
                throw new InvalidArgumentException(($prior + $advance) > 0.009
                    ? 'بڕی وەرگیراو لەگەڵ دراوی پێشوو و ئامانەت پێکەوە لە دوای داشکاندنی وەسڵ زیاترن'
                    : 'بڕی وەرگیراو لە دوای داشکاندنی وەسڵ زیاترە');
            }
            $before = (float)($invoice['draft_received_amount'] ?? 0);
            $this->db->update('case_invoices', [
                'draft_received_amount' => $amount,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id=:id', ['id' => (int)$invoiceId]);
            AuditLog::log('invoice_draft_received_changed', 'case_invoices', (int)$invoiceId,
                ['draft_received_amount' => $before],
                ['draft_received_amount' => $amount],
                $actorId ? (int)$actorId : null
            );
            $this->db->commit();
            return ['success' => true, 'invoice' => $this->getById((int)$invoiceId)];
        } catch (Throwable $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * دراوی پێشوو — money a historical case's patient paid before this system
     * existed. Saved on the draft like setDraftReceived(), and like it creates
     * no payment row; unlike it, no cash report ever reads this column, so the
     * old money settles the debt without appearing as income today.
     *
     * Only a case opened as کۆن (cases.is_historical) may carry it.
     */
    public function setPriorPaid($caseId, $invoiceId, $amount, $actorId = null) {
        $amount = round(max(0, (float)$amount), 2);
        $this->db->beginTransaction();
        try {
            $case = $this->db->fetch("SELECT is_historical FROM cases WHERE id = :id", ['id' => (int)$caseId]);
            if (empty($case['is_historical'])) {
                throw new RuntimeException('دراوی پێشوو تەنها بۆ کەیسی کۆن تۆمار دەکرێت');
            }
            $invoice = $this->db->fetch(
                "SELECT * FROM case_invoices
                 WHERE id=:id AND case_id=:case_id AND accounting_status IN ('draft','returned')
                 FOR UPDATE",
                ['id' => (int)$invoiceId, 'case_id' => (int)$caseId]
            );
            if (!$invoice) {
                throw new RuntimeException('دراوی پێشوو تەنها لەسەر وەسڵی نەنێردراو دەگۆڕدرێت');
            }
            $net = max(0, (float)$invoice['total_amount'] - (float)$invoice['discount_amount']);
            $received = max(0, (float)($invoice['draft_received_amount'] ?? 0));
            $advance = max(0, (float)($invoice['advance_paid_amount'] ?? 0));
            if ($amount > $net - $received - $advance + 0.009) {
                throw new InvalidArgumentException('دراوی پێشوو لەگەڵ وەرگیراو و ئامانەت پێکەوە لە دوای داشکاندنی وەسڵ زیاترن');
            }
            $before = (float)($invoice['prior_paid_amount'] ?? 0);
            $this->db->update('case_invoices', [
                'prior_paid_amount' => $amount,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id=:id', ['id' => (int)$invoiceId]);
            AuditLog::log('invoice_prior_paid_changed', 'case_invoices', (int)$invoiceId,
                ['prior_paid_amount' => $before],
                ['prior_paid_amount' => $amount],
                $actorId ? (int)$actorId : null
            );
            $this->db->commit();
            return ['success' => true, 'invoice' => $this->getById((int)$invoiceId)];
        } catch (Throwable $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Hand ONE draft to the accountant. Idempotent per invoice: a second press
     * finds no draft and reports it instead of sending twice.
     *
     * $intent declares how the doctor expects this receipt to be settled —
     * it is a doctor-facing HINT stored alongside the invoice, not money:
     * nothing in the ledger moves until an accountant approves an actual
     * payment. Whitelisted so a bad value fails loudly (a programming-boundary
     * contract — the caller's POST handler is expected to have already
     * filtered it) instead of quietly landing an invalid value in the column.
     *
     * @param array $intent ['payment_intent' => 'cash'|'debt'|'installment'|null,
     *                       'promised_date' => 'Y-m-d'|null, 'promised_amount' => float|null]
     * @return array ['success' => bool, 'invoice' => row]
     */
    public function send($caseId, $invoiceId, $userId, $notes = '', array $intent = []) {
        $inv = $this->getEditableDraft($caseId, $invoiceId);
        if (!$inv) {
            return ['success' => false, 'message' => 'ئەم وەسڵە پێشتر نێردراوە یان بوونی نییە'];
        }
        $this->recomputeTotal((int)$inv['id']);
        $fresh = $this->db->fetch("SELECT * FROM case_invoices WHERE id = :id", ['id' => (int)$inv['id']]);
        if ((float)$fresh['total_amount'] <= 0) {
            return ['success' => false, 'message' => 'ئەم وەسڵە بەتاڵە — سەرەتا چارەسەر زیاد بکە'];
        }

        $paymentIntent = array_key_exists('payment_intent', $intent) ? $intent['payment_intent'] : null;
        if (!in_array($paymentIntent, ['cash', 'debt', 'installment', null], true)) {
            throw new InvalidArgumentException('جۆری پارەدان نادروستە');
        }

        // The intent must agree with the money on the invoice. Invoices were
        // reaching accounting marked "cash" with the whole balance still
        // outstanding, because nothing checked the flag against the figures.
        $paymentIntent = self::deriveIntent($fresh, $paymentIntent);

        $promisedDate = $intent['promised_date'] ?? null;
        if ($promisedDate !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$promisedDate)) {
            $promisedDate = null;
        }
        $promisedAmount = (isset($intent['promised_amount']) && $intent['promised_amount'] !== '')
            ? round((float)$intent['promised_amount'], 2)
            : null;

        $changes = [
            'status'  => 'sent',
            'accounting_status' => 'sent',
            'notes'   => trim((string)$notes),
            'doctor_note_to_accounting' => trim((string)($intent['doctor_note'] ?? '')) ?: null,
            'sent_by' => $userId ? (int)$userId : ($fresh['sent_by'] ?? null),
            'sent_at' => date('Y-m-d H:i:s'),
            'returned_by' => null,
            'returned_at' => null,
            'return_reason' => null,
            'payment_intent' => $paymentIntent,
            'promised_date' => $promisedDate,
            'promised_amount' => $promisedAmount,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->update('case_invoices', $changes, 'id = :id AND accounting_status = :expected', [
                'id' => (int)$fresh['id'], 'expected' => $fresh['accounting_status']
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new Exception('Invoice state changed. Refresh and try again.');
            }
            AuditLog::logChange('invoice_sent', 'case_invoices', (int)$fresh['id'], $fresh, $changes, $userId);
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
        return ['success' => true, 'invoice' => $this->getById((int)$fresh['id'])];
    }

    /**
     * Net payable on an invoice: total after discount, minus what was already
     * taken at the chair, what was paid before the system (historical cases)
     * and what a counter advance already covers. Never negative.
     */
    public static function remainingOf(array $invoice) {
        $net = max(0, (float)$invoice['total_amount'] - (float)$invoice['discount_amount']);
        return max(0, $net
            - (float)($invoice['draft_received_amount'] ?? 0)
            - (float)($invoice['prior_paid_amount'] ?? 0)
            - (float)($invoice['advance_paid_amount'] ?? 0));
    }

    /**
     * Make the payment intent agree with the money, so an invoice can never
     * say "cash" while money is still owed.
     *
     *   nothing left to pay        -> cash        (an installment plan for a
     *                                              settled invoice is nonsense)
     *   something left, cash asked -> debt        (the correction that fixes
     *                                              the reported problem)
     *   something left, debt or installment asked -> kept as asked
     *
     * Shared by send() and accountantAdjust() on purpose: the doctor and the
     * accountant must not be able to reach different conclusions about the
     * same numbers.
     */
    public static function deriveIntent(array $invoice, $requested) {
        return self::intentForRemaining(self::remainingOf($invoice), $requested);
    }

    /**
     * The rule itself, fed a remaining figure the caller has already worked
     * out. editApproved() needs this form: on an approved invoice, receipts
     * allocated after approval also settle it, and remainingOf() — which only
     * sees the invoice's own columns — would call a fully paid invoice "debt".
     */
    private static function intentForRemaining($remaining, $requested) {
        if ($remaining <= 0.009) {
            return 'cash';
        }
        if ($requested === 'installment') {
            return 'installment';
        }
        // 'cash' with money outstanding, or nothing chosen at all, becomes debt.
        return 'debt';
    }

    /**
     * Accountant corrections on an invoice that has ALREADY been sent.
     *
     * The accountant settles the invoice, so they may set the received figure,
     * the intent and the promise. setDraftReceived() deliberately refuses once
     * an invoice leaves the doctor's hands, which is right for the doctor and
     * wrong for the accountant — hence this separate, permission-gated path.
     *
     * The intent is still passed through deriveIntent(): the accountant gets
     * full control of the numbers, but not the ability to save a contradiction
     * such as "cash" on an invoice with a balance.
     *
     * @param array $fields received_amount, payment_intent, promised_date,
     *                      promised_amount  (each optional)
     */
    public function accountantAdjust($invoiceId, array $fields, $actorId = null) {
        $this->db->beginTransaction();
        try {
            $invoice = $this->db->fetch(
                "SELECT * FROM case_invoices WHERE id = :id FOR UPDATE",
                ['id' => (int)$invoiceId]
            );
            if (!$invoice) {
                throw new RuntimeException('وەسڵ نەدۆزرایەوە');
            }
            // Approved invoices are closed. Reopening one is a different,
            // heavier operation than editing a pending one.
            if (!in_array($invoice['accounting_status'], ['sent', 'returned'], true)) {
                throw new RuntimeException('تەنها وەسڵی نێردراو دەکرێت دەستکاری بکرێت');
            }
            // The audit entry must diff against the row as it WAS. $invoice is
            // updated in place below so the intent is derived from the new
            // received figure — logging against it made every received-amount
            // change vanish from the activity log (only updated_at showed).
            $before = $invoice;

            $changes = [];
            $net = max(0, (float)$invoice['total_amount'] - (float)$invoice['discount_amount']);

            if (array_key_exists('received_amount', $fields) && $fields['received_amount'] !== '') {
                $received = round(max(0, (float)$fields['received_amount']), 2);
                $covered = max(0, (float)($invoice['prior_paid_amount'] ?? 0))
                    + max(0, (float)($invoice['advance_paid_amount'] ?? 0));
                if ($received > $net - $covered + 0.009) {
                    throw new InvalidArgumentException('بڕی وەرگیراو لە کۆی وەسڵ زیاترە');
                }
                $changes['draft_received_amount'] = $received;
                // Recompute the intent against the NEW figure, not the stored one.
                $invoice['draft_received_amount'] = $received;
            }

            if (array_key_exists('promised_date', $fields)) {
                $d = (string)$fields['promised_date'];
                $changes['promised_date'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null;
            }
            if (array_key_exists('promised_amount', $fields)) {
                $changes['promised_amount'] = ($fields['promised_amount'] === '' || $fields['promised_amount'] === null)
                    ? null
                    : round(max(0, (float)$fields['promised_amount']), 2);
            }

            $requested = array_key_exists('payment_intent', $fields)
                ? $fields['payment_intent']
                : $invoice['payment_intent'];
            if (!in_array($requested, ['cash', 'debt', 'installment', null], true)) {
                throw new InvalidArgumentException('جۆری پارەدان نادروستە');
            }
            $changes['payment_intent'] = self::deriveIntent($invoice, $requested);

            if (!$changes) {
                $this->db->rollback();
                return ['success' => true, 'invoice' => $this->getById((int)$invoiceId)];
            }

            $changes['updated_at'] = date('Y-m-d H:i:s');
            $this->db->update('case_invoices', $changes, 'id=:id', ['id' => (int)$invoiceId]);
            AuditLog::logChange('invoice_accountant_adjusted', 'case_invoices', (int)$invoiceId,
                $before, $changes, $actorId);

            $this->db->commit();
            return ['success' => true, 'invoice' => $this->getById((int)$invoiceId)];

        } catch (Throwable $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Drop an empty draft tab. Refuses once it holds work or has been sent —
     * deleting a tab must never delete treatments.
     */
    public function deleteDraft($caseId, $invoiceId) {
        require_once __DIR__ . '/Auth.php';
        if (!(new Auth())->isAdmin()) {
            return ['success' => false, 'message' => 'تەنها بەڕێوەبەر دەتوانێت پسووڵە بسڕێتەوە'];
        }
        $inv = $this->getEditableDraft($caseId, $invoiceId);
        if (!$inv || ($inv['accounting_status'] ?? '') !== 'draft') {
            return ['success' => false, 'message' => 'ناتوانرێت بسڕدرێتەوە'];
        }
        $n = $this->db->fetch(
            "SELECT COUNT(*) AS c FROM case_treatments WHERE invoice_id = :inv",
            ['inv' => (int)$inv['id']]
        );
        if ((int)($n['c'] ?? 0) > 0) {
            return ['success' => false, 'message' => 'ئەم تابە چارەسەری تێدایە — سەرەتا چارەسەرەکان بسڕەوە'];
        }
        $this->db->delete('case_invoices', 'id = :id', ['id' => (int)$inv['id']]);
        return ['success' => true];
    }

    /**
     * Legacy bridge: treatments recorded before invoices existed carry
     * invoice_id NULL. Bucket them into per-day drafts so they show up as tabs
     * like everything else. Safe to call on every page load — it does nothing
     * once the case has no orphan rows.
     */
    public function backfillDrafts($caseId, $userId = null) {
        $caseId = (int)$caseId;
        $days = $this->db->fetchAll(
            "SELECT DATE(COALESCE(performed_date, created_at)) AS work_day
             FROM case_treatments
             WHERE case_id = :cid AND invoice_id IS NULL AND status <> 'cancelled'
             GROUP BY work_day ORDER BY work_day",
            ['cid' => $caseId]
        );
        if (!$days) {
            return 0;
        }
        $made = 0;
        foreach ($days as $d) {
            $day = $d['work_day'];
            // Reuse an existing draft for that day rather than opening a second one.
            $existing = $this->db->fetch(
                "SELECT id FROM case_invoices WHERE case_id = :cid AND work_date = :d AND accounting_status = 'draft' ORDER BY invoice_seq LIMIT 1",
                ['cid' => $caseId, 'd' => $day]
            );
            $invoiceId = $existing ? (int)$existing['id'] : null;
            if (!$invoiceId) {
                $res = $this->createDraft($caseId, $day, $userId);
                if (empty($res['success'])) {
                    continue;
                }
                $invoiceId = (int)$res['invoice_id'];
                $made++;
            }
            $this->db->query(
                "UPDATE case_treatments SET invoice_id = :inv
                 WHERE case_id = :cid AND invoice_id IS NULL AND status <> 'cancelled'
                   AND DATE(COALESCE(performed_date, created_at)) = :d",
                ['inv' => $invoiceId, 'cid' => $caseId, 'd' => $day]
            );
            $this->recomputeTotal($invoiceId);
        }
        return $made;
    }

    /**
     * "Send the whole case" — used by the floating complete-case button, the
     * doctor's payment request and the resend path. Buckets any orphan work
     * into drafts first, then hands every open draft to the accountant.
     *
     * @return array ['created' => [{invoice_no, work_date, total_amount}], 'count' => int]
     */
    public function createForUninvoiced($caseId, $userId, $notes = '') {
        $caseId = (int)$caseId;
        $this->backfillDrafts($caseId, $userId);

        $drafts = $this->db->fetchAll(
            "SELECT id FROM case_invoices WHERE case_id = :cid AND accounting_status IN ('draft','returned') ORDER BY invoice_seq",
            ['cid' => $caseId]
        );
        $created = [];
        foreach ($drafts as $d) {
            $res = $this->send($caseId, (int)$d['id'], $userId, $notes);
            if (!empty($res['success'])) {
                $inv = $res['invoice'];
                $created[] = [
                    'id'           => (int)$inv['id'],
                    'invoice_no'   => $inv['invoice_no'],
                    'work_date'    => $inv['work_date'],
                    'total_amount' => (float)$inv['total_amount'],
                ];
            }
        }
        return ['created' => $created, 'count' => count($created)];
    }

    /**
     * Invoices of a case, each annotated with allocation-derived paid/remaining
     * amounts and a treatment count.
     */
    public function getByCase($caseId) {
        return $this->ledger->getInvoices((int)$caseId);
    }

    public function getById($invoiceId) {
        return $this->ledger->getInvoice((int)$invoiceId);
    }

    /**
     * Sum of not-yet-invoiced (billable) work on the case.
     */
    public function getUninvoiced($caseId) {
        $row = $this->db->fetch(
            "SELECT COUNT(*) AS cnt, COALESCE(SUM(final_price), 0) AS total
             FROM case_treatments
             WHERE case_id = :cid AND invoice_id IS NULL AND status <> 'cancelled'",
            ['cid' => (int)$caseId]
        );
        return ['count' => (int)($row['cnt'] ?? 0), 'total' => (float)($row['total'] ?? 0)];
    }

    /**
     * Not-yet-invoiced work split by day — each day is its own draft receipt,
     * exactly as it will be numbered on send.
     *
     * @return array rows of ['work_day' => 'Y-m-d', 'count' => int, 'total' => float], newest first
     */
    public function getUninvoicedDays($caseId) {
        $rows = $this->db->fetchAll(
            "SELECT DATE(COALESCE(performed_date, created_at)) AS work_day,
                    COUNT(*) AS cnt, COALESCE(SUM(final_price), 0) AS total
             FROM case_treatments
             WHERE case_id = :cid AND invoice_id IS NULL AND status <> 'cancelled'
             GROUP BY work_day
             ORDER BY work_day DESC",
            ['cid' => (int)$caseId]
        );
        return array_map(function ($r) {
            return ['work_day' => $r['work_day'], 'count' => (int)$r['cnt'], 'total' => (float)$r['total']];
        }, $rows);
    }

    /**
     * Accountant approval is independent from whether the invoice is paid.
     *
     * $auto = true is the nightly run (classes/AutoApproval.php): same rules,
     * no approver, flagged approved_auto so the پەسەندکراو tab shows 🤖 خۆکار.
     * A manual approval always clears the flag, so an invoice reopened after
     * an automatic approval and approved again by hand reads ✋ دەستی.
     */
    public function approve($caseId, $invoiceId, $actorId, $accountingNotes = '', $auto = false) {
        $this->db->beginTransaction();
        try {
            $invoice = $this->db->fetch(
                "SELECT * FROM case_invoices WHERE id=:id AND case_id=:case_id FOR UPDATE",
                ['id' => (int)$invoiceId, 'case_id' => (int)$caseId]
            );
            if (!$invoice) {
                throw new Exception('Invoice not found.');
            }
            if ($invoice['accounting_status'] === 'approved') {
                $this->db->rollback();
                return ['success' => false, 'message' => 'This invoice is already approved.'];
            }
            if ($invoice['accounting_status'] !== 'sent') {
                throw new Exception('Only an invoice sent to accounting can be approved.');
            }
            $changes = [
                'accounting_status' => 'approved',
                'accounting_notes' => trim((string)$accountingNotes),
                'approved_by' => $auto ? null : (int)$actorId,
                'approved_at' => date('Y-m-d H:i:s'),
                'approved_auto' => $auto ? 1 : 0,
                'returned_by' => null,
                'returned_at' => null,
                'return_reason' => null,
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            $this->db->update('case_invoices', $changes, 'id=:id', ['id' => (int)$invoiceId]);
            AuditLog::logChange($auto ? 'invoice_auto_approved' : 'invoice_approved', 'case_invoices', (int)$invoiceId, $invoice, $changes, $actorId);
            CashboxLedger::capture('invoice', (int)$invoiceId, $actorId ? (int)$actorId : null,
                $auto ? 'پەسەندکردنی خۆکاری وەسڵ' : 'پەسەندکردنی وەسڵ');
            $this->db->commit();
            // Money may already have been approved while the doctor kept the
            // receipt as a draft. Once accounting approves the invoice, derive
            // its final paid/partial status immediately.
            try { $this->syncStatuses((int)$caseId, $actorId ? (int)$actorId : null); }
            catch (Exception $syncError) { error_log('Invoice status sync failed: ' . $syncError->getMessage()); }
            try { (new CaseManager())->updateCaseTotals((int)$caseId); }
            catch (Exception $totalError) { error_log('Case total sync failed: ' . $totalError->getMessage()); }
            return ['success' => true, 'invoice' => $this->getById((int)$invoiceId)];
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /** Approve an invoice and its explicitly selected custody transfer together. */
    public function approveWithAdvance($caseId, $invoiceId, $actorId, $advanceAmount, $receivedCash, $accountingNotes = '') {
        $this->db->beginTransaction();
        try {
            $actor = $this->db->fetch('SELECT role,is_active FROM users WHERE id=?', [(int)$actorId]);
            if (!$actor || !$actor['is_active'] || !in_array($actor['role'], ['admin','accountant'], true)) {
                throw new DomainException('بەکارهێنانی ئامانەت تەنها بۆ ژمێریار و ئەدمینە');
            }
            $advance = CashboxLedger::cents($advanceAmount);
            $cash = CashboxLedger::cents($receivedCash);
            if ($advance < 0 || $cash < 0) throw new InvalidArgumentException('بڕی پارە نادروستە');
            // Payment writers also lock invoice rows before touching custody.
            $this->db->fetchAll('SELECT id FROM case_invoices WHERE case_id=? ORDER BY id FOR UPDATE', [(int)$caseId]);
            $invoice = $this->db->fetch('SELECT * FROM case_invoices WHERE id=? AND case_id=? FOR UPDATE', [(int)$invoiceId,(int)$caseId]);
            if (!$invoice || $invoice['accounting_status'] !== 'sent') {
                throw new DomainException('تەنها وەسڵی نێردراو کە هێشتا پەسەند نەکراوە دەکرێت پەسەند بکرێت');
            }
            // The displayed received total includes custody. Only the cash
            // remainder belongs on the invoice itself.
            $adjusted = $this->accountantAdjust($invoiceId, ['received_amount'=>CashboxLedger::decimal($cash)], $actorId);
            if (empty($adjusted['success'])) throw new RuntimeException($adjusted['message']);
            $approved = $this->approve($caseId, $invoiceId, $actorId, $accountingNotes);
            if (empty($approved['success'])) throw new RuntimeException($approved['message']);
            $funded = ['payment_id'=>null];
            if ($advance > 0) {
                require_once __DIR__ . '/PatientAdvance.php';
                $funded = (new PatientAdvance())->applyToInvoice((int)$invoiceId, $advance / 100, (int)$actorId);
                if (empty($funded['success'])) throw new RuntimeException($funded['message']);
            }
            $after = $this->getById((int)$invoiceId);
            $intent = self::intentForRemaining((float)$after['remaining_amount'], $after['payment_intent']);
            $this->db->update('case_invoices', ['payment_intent'=>$intent], 'id=?', [(int)$invoiceId]);
            AuditLog::logChange('invoice_advance_approved', 'case_invoices', (int)$invoiceId, $invoice,
                ['draft_received_amount'=>CashboxLedger::decimal($cash),'payment_intent'=>$intent], (int)$actorId);
            $this->db->commit();
            return ['success'=>true,'invoice'=>$this->getById((int)$invoiceId),'payment_id'=>$funded['payment_id']];
        } catch (Throwable $e) {
            $this->db->rollback();
            if ($e instanceof PDOException) {
                error_log('CaseInvoice::approveWithAdvance: '.$e->getMessage());
                return ['success'=>false,'message'=>'پەسەندکردن سەرنەکەوت؛ هیچ پارەیەک لە ئامانەت نەبڕدرا.'];
            }
            return ['success'=>false,'message'=>$e->getMessage()];
        }
    }

    /**
     * Edit an APPROVED invoice in place. It stays approved: accounting_status,
     * approved_by and approved_at are never written, so the invoice keeps its
     * place — and its date — in the پەسەندکراو list and in every report keyed
     * on the approval date. Only updated_at moves.
     *
     * Covers the accountant-owned figures, the same ones the Approval Center
     * edits on a sent invoice: the discount (mode + value), the received
     * figure, the cash/debt/instalment choice, the debt promise and the
     * accounting note. Every key is optional; only those present are touched.
     *
     * Gated on Settings::allowEditApprovedInvoice() here in the service, so the
     * switch holds for every caller. Callers own the role check (accountant or
     * admin) — api/finance-actions.php enforces that for the whole file.
     *
     * Approved money is booked money, so the checks count everything that
     * already settles this invoice: the chair-side figure, دراوی پێشوو, the
     * advance, and every approved or still-pending receipt allocated to it. A
     * discount can never push the invoice below what was paid, and the
     * received figure can never push paid above net. The intent is derived
     * from the REAL remaining — approved receipts included — which
     * remainingOf() cannot see.
     *
     * Money edits are refused while an instalment plan on this invoice is
     * ACTIVE: its schedule was built from these figures.
     *
     * Every edit is logged as 'invoice_approved_edited' with exactly the fields
     * that moved, so edited approved invoices can always be found afterwards.
     *
     * @param array $fields discount_mode, discount_value, received_amount,
     *                      payment_intent, promised_date, promised_amount,
     *                      accounting_notes  (each optional)
     */
    public function editApproved($invoiceId, array $fields, $actorId = null, $byDoctorPermission = false) {
        // A doctor with cases.edit_sent_invoice corrects his own approved
        // invoice's discount under that permission instead of this switch;
        // the paid-money checks below hold for him exactly the same.
        if (!$byDoctorPermission && !Settings::allowEditApprovedInvoice()) {
            return [
                'success' => false,
                'message' => 'دەستکاریکردنی وەسڵی پەسەندکراو ناچالاکە — لە ڕێکخستنەکان چالاکی بکە',
            ];
        }
        $caseId = 0;
        $this->db->beginTransaction();
        try {
            $invoice = $this->db->fetch(
                "SELECT * FROM case_invoices WHERE id = :id FOR UPDATE",
                ['id' => (int)$invoiceId]
            );
            if (!$invoice) {
                throw new RuntimeException('وەسڵ نەدۆزرایەوە');
            }
            if ($invoice['accounting_status'] !== 'approved') {
                throw new RuntimeException('ئەم وەسڵە پەسەندکراو نییە');
            }
            $caseId = (int)$invoice['case_id'];

            $touchesMoney = (bool)array_intersect(
                ['discount_mode', 'discount_value', 'received_amount', 'payment_intent'],
                array_keys($fields)
            );
            if ($touchesMoney && $this->db->fetch(
                "SELECT id FROM installment_plans WHERE invoice_id = :invoice AND status = 'active' LIMIT 1",
                ['invoice' => (int)$invoiceId]
            )) {
                throw new RuntimeException('پلانی قیستی چالاک لەسەر ئەم وەسڵە هەیە — داشکاندن و پارەکەی ناگۆڕدرێت');
            }

            $after = $invoice; // the row as it will be — what the checks below measure
            $changes = [];
            $total = max(0, (float)$invoice['total_amount']);

            if (array_key_exists('discount_mode', $fields) || array_key_exists('discount_value', $fields)) {
                $mode = (($fields['discount_mode'] ?? $invoice['discount_mode'] ?? 'amount') === 'percent') ? 'percent' : 'amount';
                $value = max(0, (float)($fields['discount_value'] ?? $invoice['discount_value'] ?? 0));
                if ($mode === 'percent' && $value > 100) {
                    throw new InvalidArgumentException('ڕێژە ناتوانێت لە ١٠٠٪ زیاتر بێت');
                }
                $amount = $mode === 'percent' ? $total * $value / 100 : $value;
                if ($amount > $total + 0.009) {
                    throw new InvalidArgumentException('داشکاندن ناتوانێت لە کۆی وەسڵەکە زیاتر بێت');
                }
                if ($amount <= 0.009) {
                    $mode = 'amount';
                    $value = 0;
                    $amount = 0;
                }
                $changes['discount_amount'] = round($amount, 2);
                $changes['discount_mode'] = $mode;
                $changes['discount_value'] = $value;
                $after['discount_amount'] = $changes['discount_amount'];
            }

            if (array_key_exists('received_amount', $fields)
                && $fields['received_amount'] !== '' && $fields['received_amount'] !== null) {
                $changes['draft_received_amount'] = round(max(0, (float)$fields['received_amount']), 2);
                $after['draft_received_amount'] = $changes['draft_received_amount'];
            }

            // Receipts allocated to this invoice: approved ones are money in
            // hand, pending ones are reserved and may still be approved.
            $alloc = $this->db->fetch(
                "SELECT COALESCE(SUM(CASE WHEN p.status = 'approved' THEN pa.allocated_amount ELSE 0 END), 0) AS approved,
                        COALESCE(SUM(CASE WHEN p.status = 'pending'  THEN pa.allocated_amount ELSE 0 END), 0) AS pending
                 FROM payment_allocations pa JOIN payments p ON p.id = pa.payment_id
                 WHERE pa.invoice_id = :invoice",
                ['invoice' => (int)$invoiceId]
            );
            $net = max(0, $total - (float)$after['discount_amount']);
            $pendingDiscount = (float)($this->db->fetch(
                "SELECT COALESCE(SUM(da.amount),0) AS amount FROM payment_discount_allocations da
                 JOIN payments p ON p.id=da.payment_id WHERE da.invoice_id=? AND p.status='pending'", [(int)$invoiceId]
            )['amount'] ?? 0);
            $paid = max(0, (float)($after['draft_received_amount'] ?? 0))
                + max(0, (float)($after['prior_paid_amount'] ?? 0))
                + max(0, (float)($after['advance_paid_amount'] ?? 0))
                + (float)($alloc['approved'] ?? 0);

            if ((isset($changes['discount_amount']) || isset($changes['draft_received_amount']))
                && $paid + (float)($alloc['pending'] ?? 0) + $pendingDiscount > $net + 0.009) {
                throw new InvalidArgumentException(isset($changes['draft_received_amount'])
                    ? 'بڕی وەرگیراو لە کۆی وەسڵ زیاترە'
                    : 'داشکاندن ناتوانێت بڕی پارەی پێشتر وەرگیراو کەم بکاتەوە');
            }

            if (array_key_exists('promised_date', $fields)) {
                $d = (string)$fields['promised_date'];
                $changes['promised_date'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null;
            }
            if (array_key_exists('promised_amount', $fields)) {
                $changes['promised_amount'] = ($fields['promised_amount'] === '' || $fields['promised_amount'] === null)
                    ? null
                    : round(max(0, (float)$fields['promised_amount']), 2);
            }

            if ($touchesMoney) {
                $requested = array_key_exists('payment_intent', $fields)
                    ? $fields['payment_intent']
                    : $invoice['payment_intent'];
                if (!in_array($requested, ['cash', 'debt', 'installment', null], true)) {
                    throw new InvalidArgumentException('جۆری پارەدان نادروستە');
                }
                $changes['payment_intent'] = self::intentForRemaining(max(0, $net - $paid), $requested);
            }

            if (array_key_exists('accounting_notes', $fields)) {
                $changes['accounting_notes'] = trim((string)$fields['accounting_notes']);
            }

            // Keep only what really moves, so the activity log names exactly
            // what was edited (the DB hands back "85000.00" for a stored 85000).
            foreach ($changes as $field => $value) {
                $old = $invoice[$field] ?? null;
                $same = (is_numeric($old) && is_numeric($value))
                    ? abs((float)$old - (float)$value) < 0.005
                    : (string)$old === (string)$value;
                if ($same) {
                    unset($changes[$field]);
                }
            }
            if (!$changes) {
                $this->db->rollback();
                return ['success' => true, 'invoice' => $this->getById((int)$invoiceId)];
            }

            $changes['updated_at'] = date('Y-m-d H:i:s');
            $this->db->update('case_invoices', $changes, 'id = :id', ['id' => (int)$invoiceId]);
            AuditLog::logChange('invoice_approved_edited', 'case_invoices', (int)$invoiceId,
                $invoice, $changes, $actorId);
            CashboxLedger::capture('invoice', (int)$invoiceId, $actorId ? (int)$actorId : null, 'دەستکاری نەقدی وەسڵ');
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            if ($e instanceof PDOException) {
                error_log('CaseInvoice::editApproved failed: ' . $e->getMessage());
                return ['success' => false, 'message' => 'هەڵەیەک ڕوویدا، دووبارە هەوڵبدەرەوە'];
            }
            return ['success' => false, 'message' => $e->getMessage()];
        }

        // Approved figures moved, so the derived paid/processed status and the
        // case totals follow at once — the same two calls approve() makes.
        // Neither touches the approval itself (status, approver, date).
        try { $this->syncStatuses($caseId, $actorId ? (int)$actorId : null); }
        catch (Exception $syncError) { error_log('Invoice status sync failed: ' . $syncError->getMessage()); }
        try { (new CaseManager())->updateCaseTotals($caseId); }
        catch (Exception $totalError) { error_log('Case total sync failed: ' . $totalError->getMessage()); }
        return ['success' => true, 'invoice' => $this->getById((int)$invoiceId)];
    }

    /**
     * Return an unapproved invoice to the doctor with a required reason.
     * The reason guard throws (mirrors Payment::updatePendingAmount /
     * Installment::rejectPlan) rather than returning ['success'=>false] —
     * a caller passing an empty reason is a programming error at the
     * boundary, not a business-state failure to report gracefully.
     */
    public function returnForCorrection($caseId, $invoiceId, $actorId, $reason) {
        $reason = trim((string)$reason);
        if ($reason === '') {
            throw new InvalidArgumentException('هۆکاری گەڕاندنەوە پێویستە');
        }
        $this->db->beginTransaction();
        try {
            $invoice = $this->db->fetch(
                "SELECT * FROM case_invoices WHERE id=:id AND case_id=:case_id FOR UPDATE",
                ['id' => (int)$invoiceId, 'case_id' => (int)$caseId]
            );
            if (!$invoice || $invoice['accounting_status'] !== 'sent') {
                throw new Exception('Only an invoice awaiting review can be returned.');
            }
            $changes = [
                'status' => 'draft',
                'accounting_status' => 'returned',
                'returned_by' => (int)$actorId,
                'returned_at' => date('Y-m-d H:i:s'),
                'return_reason' => $reason,
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            $this->db->update('case_invoices', $changes, 'id=:id', ['id' => (int)$invoiceId]);
            foreach ($this->db->fetchAll(
                "SELECT p.* FROM payments p JOIN payment_allocations pa ON pa.payment_id=p.id
                 WHERE pa.invoice_id=:invoice AND p.status='pending' FOR UPDATE",
                ['invoice' => (int)$invoiceId]
            ) as $pendingPayment) {
                $this->db->update('payments', [
                    'status' => 'rejected',
                    'rejected_by' => (int)$actorId,
                    'notes' => trim((string)$pendingPayment['notes'] . "\nInvoice returned for correction: " . $reason),
                ], 'id=:id', ['id' => (int)$pendingPayment['id']]);
                AuditLog::log('payment_request_rejected_with_invoice', 'payments', (int)$pendingPayment['id'],
                    ['status' => 'pending'], ['status' => 'rejected'], $actorId);
            }
            AuditLog::logChange('invoice_returned', 'case_invoices', (int)$invoiceId, $invoice, $changes, $actorId);
            $this->db->commit();
            return ['success' => true];
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * The doctor takes back a receipt they sent, before the accountant has
     * acted on it, so they can correct it and send it again. The mirror of
     * returnForCorrection(), started from the doctor's side.
     *
     * Refused once accounting has touched the receipt: it is approved, money
     * was booked or requested against it, or its instalment plan was already
     * activated. A still-pending plan proposed with the send is cancelled —
     * resending would otherwise silently reuse the old terms.
     */
    public function recall($caseId, $invoiceId, $actorId) {
        $this->db->beginTransaction();
        try {
            $invoice = $this->db->fetch(
                "SELECT * FROM case_invoices WHERE id=:id AND case_id=:case_id FOR UPDATE",
                ['id' => (int)$invoiceId, 'case_id' => (int)$caseId]
            );
            if (!$invoice) {
                throw new RuntimeException('وەسڵ نەدۆزرایەوە');
            }
            if ($invoice['accounting_status'] !== 'sent') {
                throw new RuntimeException($invoice['accounting_status'] === 'approved'
                    ? 'ژمێریار ئەم وەسڵەی پەسەندکردووە — ناتوانرێت کانسڵ بکرێتەوە'
                    : 'تەنها وەسڵێکی نێردراو کە هێشتا پەسەند نەکراوە کانسڵ دەکرێتەوە');
            }
            $allocated = $this->db->fetch(
                "SELECT COALESCE(SUM(CASE WHEN p.status='approved' THEN pa.allocated_amount ELSE 0 END),0) AS approved,
                        COALESCE(SUM(CASE WHEN p.status='pending' THEN pa.allocated_amount ELSE 0 END),0) AS pending
                 FROM payment_allocations pa JOIN payments p ON p.id=pa.payment_id
                 WHERE pa.invoice_id=:invoice",
                ['invoice' => (int)$invoiceId]
            );
            if ((float)($allocated['approved'] ?? 0) > 0.009) {
                throw new RuntimeException('ژمێریار پارەی لەسەر ئەم وەسڵە وەرگرتووە — ناتوانرێت کانسڵ بکرێتەوە');
            }
            if ((float)($allocated['pending'] ?? 0) > 0.009) {
                throw new RuntimeException('داواکاری پارەدانی چاوەڕوان لەسەر ئەم وەسڵە هەیە — ناتوانرێت کانسڵ بکرێتەوە');
            }
            $plans = $this->db->fetchAll(
                "SELECT * FROM installment_plans WHERE invoice_id=:invoice AND status IN ('pending','active') FOR UPDATE",
                ['invoice' => (int)$invoiceId]
            );
            foreach ($plans as $plan) {
                if ($plan['status'] === 'active') {
                    throw new RuntimeException('پلانی قیستی ئەم وەسڵە چالاک کراوە — ناتوانرێت کانسڵ بکرێتەوە');
                }
            }

            $changes = [
                'status' => 'draft',
                'accounting_status' => 'draft',
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            $stmt = $this->db->update('case_invoices', $changes, 'id = :id AND accounting_status = :expected', [
                'id' => (int)$invoiceId, 'expected' => 'sent'
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('دۆخی وەسڵەکە گۆڕاوە — پەڕەکە نوێ بکەرەوە');
            }
            foreach ($plans as $plan) {
                $this->db->update('installment_plans', ['status' => 'cancelled'], 'id=:id', ['id' => (int)$plan['id']]);
                AuditLog::log('installment_plan_cancelled_with_invoice_recall', 'installment_plans', (int)$plan['id'],
                    ['status' => 'pending'], ['status' => 'cancelled'], $actorId);
            }
            AuditLog::logChange('invoice_recalled', 'case_invoices', (int)$invoiceId, $invoice, $changes, $actorId);
            $this->db->commit();
            return ['success' => true, 'invoice' => $this->getById((int)$invoiceId)];
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Set this receipt's own discount, remembering HOW it was given so a
     * percentage keeps reading as a percentage. Capped at the receipt's total —
     * a discount can zero a bill but never make it negative.
     *
     * @param string $mode  'amount' (value is money) or 'percent' (value is %)
     */
    public function setDiscount($caseId, $invoiceId, $mode, $value, $actorId = null) {
        $this->db->beginTransaction();
        try {
            // This is the same invoice lock used by payment allocation. It makes
            // the discount/payment capacity check atomic under concurrency.
            $inv = $this->db->fetch(
                "SELECT * FROM case_invoices WHERE id=:id AND case_id=:cid FOR UPDATE",
                ['id' => (int)$invoiceId, 'cid' => (int)$caseId]
            );
            if (!$inv) throw new Exception('وەسڵ نەدۆزرایەوە');
            if (in_array($inv['accounting_status'] ?? '', ['approved', 'cancelled'], true)) {
                throw new Exception('وەسڵی پەسەندکراو دەستکاری ناکرێت');
            }

            $mode = $mode === 'percent' ? 'percent' : 'amount';
            $value = max(0, (float)$value);
            $total = (float)$inv['total_amount'];
            if ($mode === 'percent') {
                if ($value > 100) throw new Exception('ڕێژە ناتوانێت لە ١٠٠٪ زیاتر بێت');
                $amount = $total * $value / 100;
            } else {
                $amount = $value;
            }
            if ($amount > $total + 0.009) {
                throw new Exception('داشکاندن ناتوانێت لە کۆی وەسڵەکە زیاتر بێت');
            }
            if ($amount <= 0) {
                $mode = 'amount';
                $value = 0;
            }
            $allocatedPaid = (float)($this->db->fetch(
                "SELECT COALESCE(SUM(pa.allocated_amount),0) AS amount
                 FROM payment_allocations pa JOIN payments p ON p.id=pa.payment_id
                 WHERE pa.invoice_id=:invoice AND p.status='approved'",
                ['invoice' => (int)$invoiceId]
            )['amount'] ?? 0);
            $paid = min($total, max(0, (float)($inv['draft_received_amount'] ?? 0))
                + max(0, (float)($inv['prior_paid_amount'] ?? 0))
                + max(0, (float)($inv['advance_paid_amount'] ?? 0)) + $allocatedPaid);
            $pendingDiscount = (float)($this->db->fetch(
                "SELECT COALESCE(SUM(da.amount),0) AS amount FROM payment_discount_allocations da
                 JOIN payments p ON p.id=da.payment_id WHERE da.invoice_id=? AND p.status='pending'", [(int)$invoiceId]
            )['amount'] ?? 0);
            if ($pendingDiscount > 0) {
                $pendingCash = (float)($this->db->fetch(
                    "SELECT COALESCE(SUM(pa.allocated_amount),0) AS amount FROM payment_allocations pa
                     JOIN payments p ON p.id=pa.payment_id WHERE pa.invoice_id=? AND p.status='pending'", [(int)$invoiceId]
                )['amount'] ?? 0);
                $paid += $pendingDiscount + $pendingCash;
            }
            if ($total - $amount < $paid - 0.009) {
                throw new Exception('Discount cannot reduce the invoice below payments already allocated to it.');
            }
            $changes = [
                'discount_amount' => round($amount, 2),
                'discount_mode' => $mode,
                'discount_value' => $value,
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            // A discount changes what is left to pay, so the intent has to be
            // re-derived here too. Without this, discounting an invoice down to
            // fully-covered would leave it flagged "debt", and discounting a
            // paid invoice upward would leave "cash" on a balance — the exact
            // contradiction this rule exists to prevent.
            $afterDiscount = $inv;
            $afterDiscount['discount_amount'] = round($amount, 2);
            $changes['payment_intent'] = self::deriveIntent($afterDiscount, $inv['payment_intent']);

            $this->db->update('case_invoices', $changes, 'id = :id', ['id' => (int)$inv['id']]);
            AuditLog::logChange('invoice_discount_changed', 'case_invoices', (int)$inv['id'], $inv, $changes, $actorId);
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
        $this->syncStatuses($caseId);
        return ['success' => true];
    }

    /**
     * Update an invoice's free-text accounting note in isolation, without
     * touching its discount. Extracted from accountant/report-detail.php's
     * own inline update_invoice_accounting handler (:97-102) so the API
     * endpoint (api/finance-actions.php) can call a service method instead
     * of running SQL directly — report-detail.php keeps its original inline
     * copy untouched.
     */
    public function updateAccountingNotes($invoiceId, $notes, $actorId = null) {
        $invoiceId = (int)$invoiceId;
        $old = $this->db->fetch('SELECT accounting_notes FROM case_invoices WHERE id=:id', ['id' => $invoiceId]);
        if (!$old) {
            return ['success' => false, 'message' => 'وەسڵ نەدۆزرایەوە'];
        }
        $newNote = trim((string)$notes);
        if ((string)($old['accounting_notes'] ?? '') !== $newNote) {
            $this->db->update('case_invoices', [
                'accounting_notes' => $newNote,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id=:id', ['id' => $invoiceId]);
            AuditLog::logChange('invoice_accounting_note_changed', 'case_invoices', $invoiceId, $old, ['accounting_notes' => $newNote], $actorId);
        }
        return ['success' => true];
    }

    /**
     * Recompute total_amount from the linked rows (after an admin edited or
     * deleted a billed treatment), then recalculate its derived status.
     */
    public function recomputeTotal($invoiceId) {
        $invoiceId = (int)$invoiceId;
        $inv = $this->db->fetch("SELECT id, case_id, total_amount, discount_amount, discount_mode, discount_value FROM case_invoices WHERE id = :id", ['id' => $invoiceId]);
        if (!$inv) {
            return;
        }
        $row = $this->db->fetch(
            "SELECT COALESCE(SUM(final_price), 0) AS total FROM case_treatments WHERE invoice_id = :inv AND status <> 'cancelled'",
            ['inv' => $invoiceId]
        );
        $total = (float)$row['total'];
        // A percentage discount follows the new total automatically; a flat one
        // is clamped so removing work can never leave the receipt negative.
        $discount = ($inv['discount_mode'] ?? 'amount') === 'percent'
            ? $total * (float)($inv['discount_value'] ?? 0) / 100
            : min((float)($inv['discount_amount'] ?? 0), $total);
        $changes = [
            'total_amount'    => $total,
            'discount_amount' => round(max(0, $discount), 2),
        ];
        $this->db->update('case_invoices', $changes, 'id = :id', ['id' => $invoiceId]);
        if (abs((float)$inv['total_amount'] - $total) > 0.009
            || abs((float)$inv['discount_amount'] - (float)$changes['discount_amount']) > 0.009) {
            AuditLog::logChange('invoice_total_recalculated', 'case_invoices', $invoiceId, $inv, $changes, $_SESSION['user_id'] ?? null);
        }
        $this->syncStatuses((int)$inv['case_id']);
    }

    /**
     * Recalculate the legacy display status from approved allocations. Flip a
     * fully settled approved invoice to processed, or reopen it when reversed.
     * Notifies the sending doctor about each invoice that just settled —
     * transitions happen exactly once, so notifications cannot duplicate.
     *
     * @return array invoice rows that flipped to processed in THIS call
     */
    public function syncStatuses($caseId, $actorId = null) {
        $caseId = (int)$caseId;
        $case = $this->db->fetch(
            "SELECT c.id, p.full_name AS patient_name
             FROM cases c
             INNER JOIN patient_folders pf ON c.folder_id = pf.id
             LEFT JOIN patients p ON pf.patient_id = p.id
             WHERE c.id = :id",
            ['id' => $caseId]
        );
        if (!$case) {
            return [];
        }
        // Drafts are excluded: the accountant can only collect against what was
        // actually sent to them, so an un-sent tab must never absorb money and
        // leave the real (sent) receipt looking unpaid.
        $invoices = $this->ledger->getInvoices($caseId);
        if (!$invoices) {
            return [];
        }

        $settledNow = [];
        foreach ($invoices as $inv) {
            if (($inv['accounting_status'] ?? 'draft') !== 'approved') {
                continue;
            }
            $isSettled = (float)$inv['remaining_amount'] <= 0.009;

            if ($isSettled && $inv['status'] !== 'processed') {
                $this->db->update('case_invoices', [
                    'status'       => 'processed',
                    'processed_by' => $actorId ? (int)$actorId : null,
                    'processed_at' => date('Y-m-d H:i:s'),
                ], 'id = :id', ['id' => $inv['id']]);
                $settledNow[] = $inv;
                AuditLog::log('invoice_paid', 'case_invoices', (int)$inv['id'],
                    ['status' => $inv['status']], ['status' => 'processed'], $actorId);
            } elseif (!$isSettled && $inv['status'] === 'processed') {
                // Coverage receded (edit/refund) — reopen so it isn't lost.
                $this->db->update('case_invoices', [
                    'status' => 'sent', 'processed_by' => null, 'processed_at' => null,
                ], 'id = :id', ['id' => $inv['id']]);
                AuditLog::log('invoice_reopened_after_payment_reversal', 'case_invoices', (int)$inv['id'],
                    ['status' => 'processed'], ['status' => 'sent'], $actorId);
            }
        }

        foreach ($settledNow as $inv) {
            $this->notifyInvoiceSettled($inv, $case['patient_name'] ?? '', $actorId);
        }
        return $settledNow;
    }

    /**
     * Tell the doctor who sent the invoice that it is fully covered.
     */
    private function notifyInvoiceSettled($invoice, $patientName, $actorId = null) {
        $doctorUserId = (int)($invoice['sent_by'] ?? 0);
        if (!$doctorUserId || $doctorUserId === (int)$actorId) {
            return; // unknown sender, or the doctor settled it themselves
        }
        require_once __DIR__ . '/Notification.php';
        $data = ['case_id' => (int)$invoice['case_id'], 'invoice_no' => $invoice['invoice_no'], 'patient_name' => $patientName];
        (new Notification())->notifyUsers(
            [$doctorUserId],
            Notification::TYPE_PAYMENT_APPROVED,
            'وەسڵ تەواو بوو',
            "وەسڵی {$invoice['invoice_no']}" . ($patientName !== '' ? " (نەخۆش {$patientName})" : '')
                . " پارەکەی بە تەواوی وەرگیرا — " . number_format((float)$invoice['total_amount']),
            $data,
            Notification::resolveLink(Notification::TYPE_PAYMENT_APPROVED, $data),
            $actorId
        );
    }

    /**
     * May this receipt be put on an instalment plan yet?
     *
     * Plans attach to one approved invoice and payments settle it through the
     * same allocation ledger as ordinary receipts.
     *
     * @return array ['allowed'=>bool, 'message'=>string, 'remaining'=>float]
     */
    public function installmentEligibility($caseId, $invoiceId) {
        $caseId    = (int)$caseId;
        $invoiceId = (int)$invoiceId;
        $inv = null;
        foreach ($this->getByCase($caseId) as $row) {
            if ((int)$row['id'] === $invoiceId) { $inv = $row; break; }
        }
        if (!$inv) {
            return ['allowed' => false, 'message' => 'وەسڵ نەدۆزرایەوە', 'remaining' => 0];
        }
        if (($inv['accounting_status'] ?? 'draft') !== 'approved') {
            return ['allowed' => false, 'message' => 'ئەم وەسڵە هێشتا نەنێردراوە', 'remaining' => 0];
        }
        $remaining = (float)$inv['remaining_amount'];
        if ($remaining <= 0.009) {
            return ['allowed' => false, 'message' => 'ئەم وەسڵە پارەکەی تەواو دراوە', 'remaining' => 0];
        }
        return ['allowed' => true, 'message' => '', 'remaining' => $remaining];
    }

    /**
     * Translate a requested cumulative invoice-paid figure into a new payment
     * amount. Existing money comes only from that invoice's valid allocations.
     *
     * @return array ['success'=>bool, 'message'=>string] on refusal, else
     *   ['success'=>true,'net','target','amount','prior_total','prior'=>[rows],
     *    'was_draft'=>bool,'invoice_no'=>string]
     */
    public function planPaidTarget($caseId, $invoiceId, $target) {
        $caseId    = (int)$caseId;
        $invoiceId = (int)$invoiceId;
        $inv = $this->db->fetch(
            "SELECT * FROM case_invoices WHERE id = :id AND case_id = :cid",
            ['id' => $invoiceId, 'cid' => $caseId]
        );
        if (!$inv) {
            return ['success' => false, 'message' => 'وەسڵ نەدۆزرایەوە'];
        }
        $case = $this->db->fetch(
            "SELECT total_amount, paid_amount, discount_amount FROM cases WHERE id = :id",
            ['id' => $caseId]
        );
        if (!$case) {
            return ['success' => false, 'message' => 'کەیس نەدۆزرایەوە'];
        }

        $net    = max(0, (float)$inv['total_amount'] - (float)$inv['discount_amount']);
        $target = min(max(0, (float)$target), $net);

        // Explicit allocations remove the old cross-invoice waterfall. The
        // amount typed on an invoice affects that invoice only.
        $current = $this->getById($invoiceId);
        $currentCovered = (float)($current['paid_amount'] ?? 0);
        $amount = round($target - $currentCovered, 2);
        if (abs($amount) <= 0.009) {
            return ['success' => false, 'message' => 'No change was made.'];
        }
        if ($amount < 0) {
            return ['success' => false, 'message' => 'Use payment reversal to reduce an amount already received.'];
        }
        return [
            'success' => true,
            'invoice_no' => $inv['invoice_no'],
            'was_draft' => ($inv['accounting_status'] ?? 'draft') === 'draft',
            'net' => $net,
            'target' => $target,
            'covered' => $currentCovered,
            'prior' => [],
            'prior_total' => 0,
            'amount' => $amount,
        ];
    }
}
