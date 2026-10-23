<?php
/**
 * PatientLedger — one patient's money as a flat, chronological, running-
 * balance ledger (Date | Reference | Description | Debit | Credit | Balance),
 * in the same shape as ErpLedger::customerStatement() so patients/statement.php
 * can render it with the same "opening → rows → closing" table the ERP side
 * already uses.
 *
 * This computes NOTHING that PatientFinance/AccountingLedger do not already
 * treat as real money — it only re-shapes case_invoices/payments/x-ray debt
 * rows into debit/credit lines and folds in the one new concept this system
 * did not have before: a per-patient opening balance (old debt carried
 * forward from before the system tracked it).
 *
 * Why installment_payments and patient_credit_logs are NOT queried here
 * (checked against the schema, not assumed):
 *   - Every installment collection is written to `payments` first
 *     (Installment.php's applyFlexibleToPlan/activatePlan all go through
 *     Payment::directPayment); installment_payments only links back to that
 *     row via payment_id. Summing both would double the same cash.
 *   - patient_credit_logs never represents NEW cash: 'credit' rows are
 *     written when a payment OVERPAYS a debt (DebtPayment::addPatientCredit,
 *     called from inside distributePayment — the full overpayment amount is
 *     already the `payments` row's amount); 'debit' rows are written when
 *     that stored credit is SPENT against a bill (DebtPayment::useCredit
 *     calls distributePayment again, which is — again — a normal `payments`
 *     row). Including patient_credit_logs here would double-count both ways.
 *   - X-ray money is verified separate: XrayManager never writes to
 *     `payments` (only patient_xray_debts / xray_debt_payments), so it is
 *     the one source that genuinely needs its own rows here.
 *
 * Cash-settled ('cash'/'dont_cash') X-ray requests are deliberately excluded:
 * they never became debt (no patient_xray_debts row), so they never moved
 * this balance — this ledger tracks money owed, not a full point-of-sale
 * journal of every transaction that ever touched the counter.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Database.php';

class PatientLedger
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function getOpeningBalance(int $patientId): array
    {
        $row = $this->db->fetch(
            'SELECT * FROM patient_opening_balances WHERE patient_id = :id',
            ['id' => $patientId]
        );
        return $row ?: ['amount' => 0.0, 'as_of_date' => null, 'notes' => null];
    }

    /** Admin/accountant only — enforced by the caller (api/finance-actions.php). */
    public function setOpeningBalance(int $patientId, float $amount, string $asOfDate, ?string $notes, int $actorId): void
    {
        $amount = round(max(0, $amount), 2);
        $existing = $this->db->fetch(
            'SELECT id FROM patient_opening_balances WHERE patient_id = :id',
            ['id' => $patientId]
        );
        if ($existing) {
            $this->db->update(
                'patient_opening_balances',
                ['amount' => $amount, 'as_of_date' => $asOfDate, 'notes' => $notes, 'updated_by' => $actorId],
                'id = :id',
                ['id' => $existing['id']]
            );
        } else {
            $this->db->insert('patient_opening_balances', [
                'patient_id' => $patientId,
                'amount' => $amount,
                'as_of_date' => $asOfDate,
                'notes' => $notes,
                'created_by' => $actorId,
            ]);
        }
    }

    /**
     * @return array ['opening_row'=>opening balance row (for the edit form),
     *   'opening'=>float (the balance the displayed rows start from),
     *   'rows'=>[['entry_date','doc_type','doc_number','description','debit','credit','balance']],
     *   'totals'=>['debit','credit'], 'closing'=>float]
     */
    public function statement(int $patientId, ?int $doctorUserId = null, ?string $from = null, ?string $to = null): array
    {
        $openingRow = $this->getOpeningBalance($patientId);
        $balance = (float)($openingRow['amount'] ?? 0);

        $allRows = $this->collectRows($patientId, $doctorUserId);
        foreach ($allRows as &$r) {
            $balance += $r['debit'] - $r['credit'];
            $r['balance'] = $balance;
        }
        unset($r);

        $displayOpening = (float)($openingRow['amount'] ?? 0);
        $filtered = [];
        $totalDebit = 0.0;
        $totalCredit = 0.0;
        foreach ($allRows as $r) {
            $rowDate = substr($r['entry_date'], 0, 10);
            if ($from !== null && $from !== '' && $rowDate < $from) {
                $displayOpening = $r['balance']; // carries forward as the pre-range position
                continue;
            }
            if ($to !== null && $to !== '' && $rowDate > $to) {
                continue;
            }
            $filtered[] = $r;
            $totalDebit += $r['debit'];
            $totalCredit += $r['credit'];
        }
        $closing = $filtered ? end($filtered)['balance'] : $displayOpening;

        return [
            'opening_row' => $openingRow,
            'opening' => $displayOpening,
            'rows' => $filtered,
            'totals' => ['debit' => $totalDebit, 'credit' => $totalCredit],
            'closing' => $closing,
        ];
    }

    /** Every debit/credit-affecting row, unfiltered, sorted chronologically. */
    private function collectRows(int $patientId, ?int $doctorUserId): array
    {
        $rows = [];

        $caseParams = ['pid' => $patientId];
        $caseSql = "SELECT c.id, c.case_number, c.case_name, pf.folder_name
                     FROM cases c JOIN patient_folders pf ON pf.id = c.folder_id
                     WHERE pf.patient_id = :pid";
        if ($doctorUserId !== null) {
            $caseSql .= ' AND pf.doctor_id = :did';
            $caseParams['did'] = $doctorUserId;
        }
        $caseRows = $this->db->fetchAll($caseSql, $caseParams);
        $caseIds = array_map(fn($c) => (int)$c['id'], $caseRows);
        $caseLabel = [];
        foreach ($caseRows as $c) {
            $caseLabel[(int)$c['id']] = $c['folder_name'] . ' — ' . ($c['case_name'] ?: $c['case_number']);
        }

        if ($caseIds) {
            $idList = implode(',', $caseIds);

            $invoices = $this->db->fetchAll(
                "SELECT id, case_id, invoice_no, work_date, total_amount, discount_amount,
                        draft_received_amount, prior_paid_amount, advance_paid_amount
                 FROM case_invoices WHERE case_id IN ($idList) AND accounting_status = 'approved'"
            );
            foreach ($invoices as $inv) {
                $gross = (float)$inv['total_amount'];
                $disc = (float)$inv['discount_amount'];
                $net = max(0, $gross - $disc);
                $label = $caseLabel[(int)$inv['case_id']] ?? '';
                $rows[] = [
                    'entry_date' => $inv['work_date'] . ' 00:00:01',
                    'doc_type' => 'INVOICE',
                    'doc_number' => $inv['invoice_no'],
                    'description' => 'وەسڵی چارەسەر — ' . $label,
                    'debit' => $gross,
                    'credit' => 0.0,
                    'case_id' => (int)$inv['case_id'],
                    'ref_id' => (int)$inv['id'],
                ];
                if ($disc > 0.009) {
                    $rows[] = [
                        'entry_date' => $inv['work_date'] . ' 00:00:02',
                        'doc_type' => 'DISCOUNT',
                        'doc_number' => $inv['invoice_no'],
                        'description' => 'داشکاندنی وەسڵ — ' . $inv['invoice_no'],
                        'debit' => 0.0,
                        'credit' => $disc,
                        'case_id' => (int)$inv['case_id'],
                        'ref_id' => (int)$inv['id'],
                    ];
                }
                // Chair-side cash recorded directly on the invoice — this never
                // becomes a `payments` row (CaseInvoice::setDraftReceived() is
                // explicit that it must not), so without this the ledger would
                // show a patient who paid in full at the chair as still owing
                // everything. Capped at net, matching AccountingLedger's own
                // LEAST(net, draft_received_amount) guard.
                $chairReceived = min($net, max(0, (float)($inv['draft_received_amount'] ?? 0)));
                if ($chairReceived > 0.009) {
                    $rows[] = [
                        'entry_date' => $inv['work_date'] . ' 00:00:03',
                        'doc_type' => 'CHAIR_PAYMENT',
                        'doc_number' => $inv['invoice_no'],
                        'description' => 'نەقدی لای دکتۆر — ' . $inv['invoice_no'],
                        'debit' => 0.0,
                        'credit' => $chairReceived,
                        'case_id' => (int)$inv['case_id'],
                        'ref_id' => (int)$inv['id'],
                    ];
                }
                // Historical case: money paid before this system existed. It
                // lowers the balance like chair cash, but is its own row type
                // so the statement reads "دراوی پێشوو" and not cash taken here.
                $priorPaid = min(max(0, $net - $chairReceived), max(0, (float)($inv['prior_paid_amount'] ?? 0)));
                if ($priorPaid > 0.009) {
                    $rows[] = [
                        'entry_date' => $inv['work_date'] . ' 00:00:04',
                        'doc_type' => 'PRIOR_PAYMENT',
                        'doc_number' => $inv['invoice_no'],
                        'description' => 'دراوی پێشوو (پێش سیستەم) — ' . $inv['invoice_no'],
                        'debit' => 0.0,
                        'credit' => $priorPaid,
                        'case_id' => (int)$inv['case_id'],
                        'ref_id' => (int)$inv['id'],
                    ];
                }
                // ئامانەت put on this invoice. The cash itself is already a
                // credit on this statement, posted on the day the counter took
                // it (the ADVANCE rows below), so this is only a note of where
                // it went — a credit here too would pay the bill twice.
                $advancePaid = min(
                    max(0, $net - $chairReceived - $priorPaid),
                    max(0, (float)($inv['advance_paid_amount'] ?? 0))
                );
                if ($advancePaid > 0.009) {
                    $rows[] = [
                        'entry_date' => $inv['work_date'] . ' 00:00:05',
                        'doc_type' => 'ADVANCE_APPLIED',
                        'doc_number' => $inv['invoice_no'],
                        'description' => 'ئامانەت دانرا لەسەر ' . $inv['invoice_no'],
                        'debit' => 0.0,
                        'credit' => 0.0,
                        'case_id' => (int)$inv['case_id'],
                        'ref_id' => (int)$inv['id'],
                        'note_amount' => $advancePaid,
                    ];
                }
            }

            // Payments: 'approved' posts a credit at payment_date. 'refunded'
            // still posts that same original credit (it WAS real money at the
            // time), then a reversing debit at reversed_at — a true history,
            // not an erased one.
            $methodLabels = ['cash' => 'نەقد', 'card' => 'کارت', 'transfer' => 'گواستنەوە', 'other' => 'هیتر', 'advance' => 'لە ئامانەت'];
            $payments = $this->db->fetchAll(
                "SELECT id, case_id, payment_number, payment_date, amount, payment_method, status, reversed_at
                 FROM payments WHERE case_id IN ($idList) AND status IN ('approved', 'refunded')"
            );
            foreach ($payments as $p) {
                $label = $caseLabel[(int)$p['case_id']] ?? '';
                $method = $methodLabels[$p['payment_method']] ?? $p['payment_method'];
                $rows[] = [
                    'entry_date' => $p['payment_date'],
                    'doc_type' => 'PAYMENT',
                    'doc_number' => $p['payment_number'],
                    'description' => 'پارەدان (' . $method . ') — ' . $label,
                    'debit' => 0.0,
                    'credit' => (float)$p['amount'],
                    'case_id' => (int)$p['case_id'],
                    'ref_id' => (int)$p['id'],
                ];
                if ($p['status'] === 'refunded' && !empty($p['reversed_at'])) {
                    $rows[] = [
                        'entry_date' => $p['reversed_at'],
                        'doc_type' => 'REFUND',
                        'doc_number' => $p['payment_number'],
                        'description' => 'گەڕاندنەوەی پارەدان — ' . $p['payment_number'],
                        'debit' => (float)$p['amount'],
                        'credit' => 0.0,
                        'case_id' => (int)$p['case_id'],
                        'ref_id' => (int)$p['id'],
                    ];
                }
            }
        }

        // X-ray debt only — cash/free X-rays never became debt, so they never
        // move this balance (see the class-level note).
        $xrParams = ['pid' => $patientId];
        $xrSql = "SELECT pxd.id, pxd.amount, pxd.created_at, xr.request_number, xr.xray_type
                   FROM patient_xray_debts pxd JOIN xray_requests xr ON xr.id = pxd.request_id
                   WHERE pxd.patient_id = :pid";
        if ($doctorUserId !== null) {
            $xrSql .= ' AND xr.requested_by = :did';
            $xrParams['did'] = $doctorUserId;
        }
        $xrayDebts = $this->db->fetchAll($xrSql, $xrParams);
        $debtRef = [];
        foreach ($xrayDebts as $xd) {
            $debtRef[(int)$xd['id']] = $xd['request_number'];
            $rows[] = [
                'entry_date' => $xd['created_at'],
                'doc_type' => 'XRAY',
                'doc_number' => $xd['request_number'],
                'description' => 'قەرزی ئیکسڕەی — ' . ($xd['xray_type'] ?? ''),
                'debit' => (float)$xd['amount'],
                'credit' => 0.0,
            ];
        }
        if ($xrayDebts) {
            $debtIds = implode(',', array_map(fn($d) => (int)$d['id'], $xrayDebts));
            $xrayPays = $this->db->fetchAll("SELECT * FROM xray_debt_payments WHERE debt_id IN ($debtIds)");
            foreach ($xrayPays as $xp) {
                $rows[] = [
                    'entry_date' => $xp['created_at'],
                    'doc_type' => 'XRAY_PAYMENT',
                    'doc_number' => $debtRef[(int)$xp['debt_id']] ?? ('#' . $xp['debt_id']),
                    'description' => 'پارەدانی قەرزی ئیکسڕەی',
                    'debit' => 0.0,
                    'credit' => (float)$xp['amount'],
                ];
            }
        }

        // Custody receipts/refunds are notes. Only a canonical approved payment
        // settles patient debt, so applying custody cannot credit it twice.
        //
        // Doctor-scoped statements leave these out: an advance is not owned by
        // any doctor — it is taken at reception before anyone knows whose case
        // it will land on — so attributing it to the doctor being viewed would
        // be a guess.
        if ($doctorUserId === null) {
            $advances = $this->db->fetchAll(
                "SELECT id, advance_no, amount, received_at, refunded_amount, refunded_at
                 FROM patient_advances WHERE patient_id = :pid",
                ['pid' => $patientId]
            );
            foreach ($advances as $ad) {
                $rows[] = [
                    'entry_date' => $ad['received_at'],
                    'doc_type' => 'ADVANCE',
                    'doc_number' => $ad['advance_no'],
                    'description' => 'ئامانەتی وەرگیراو لە پێشوازی',
                    'debit' => 0.0,
                    'credit' => 0.0,
                    'note_amount' => (float)$ad['amount'],
                ];
                if ((float)$ad['refunded_amount'] > 0.009 && !empty($ad['refunded_at'])) {
                    $rows[] = [
                        'entry_date' => $ad['refunded_at'],
                        'doc_type' => 'ADVANCE_REFUND',
                        'doc_number' => $ad['advance_no'],
                        'description' => 'گەڕاندنەوەی ئامانەت',
                        'debit' => 0.0,
                        'credit' => 0.0,
                        'note_amount' => (float)$ad['refunded_amount'],
                    ];
                }
            }
        }

        usort($rows, function ($a, $b) {
            return strcmp($a['entry_date'], $b['entry_date']);
        });
        return $rows;
    }
}
