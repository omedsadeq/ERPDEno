<?php
/**
 * PatientFinance — read-only aggregation of one patient's whole financial
 * picture, composed entirely from existing SERVICE outputs (AccountingLedger,
 * Installment, XrayManager, DebtPayment). Per spec §9 this class computes NO
 * money that those services do not already compute — it only resolves the
 * patient's scoped case ids, loops/merges their outputs, and sums totals.
 *
 * v_patient_financial_summary (Task 2) provides the same case_balance figure
 * as a plain SQL view for list pages. summary()'s case_balance is guaranteed
 * to agree with it because both sum the identical per-case formula — this
 * class loops AccountingLedger::getSummary($caseId)['balance'], the view sums
 * v_case_financial_summary.balance, and both of those in turn are
 * max(0, approved - paid) computed per case from case_invoices/payment_allocations.
 * (See tests/cases/PatientFinanceTest.php's view-vs-service assertion.)
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/AccountingLedger.php';
require_once __DIR__ . '/Installment.php';
require_once __DIR__ . '/XrayManager.php';
require_once __DIR__ . '/DebtPayment.php';

class PatientFinance
{
    private $db;
    private $ledger;
    private $installment;
    private $xrayManager;
    private $debtPayment;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->ledger = new AccountingLedger();
        $this->installment = new Installment();
        $this->xrayManager = new XrayManager();
        $this->debtPayment = new DebtPayment();
    }

    // ================================================================
    // summary()
    // ================================================================

    /**
     * @return array ['total_invoiced','total_discount','approved','paid',
     *   'pending_requests','case_balance','xray_outstanding','credit',
     *   'installment_remaining','net_debt'] — all floats.
     *   net_debt = max(0, case_balance + xray_outstanding - credit).
     */
    public function summary(int $patientId, ?int $doctorUserId = null): array
    {
        $caseIds = $this->scopedCaseIds($patientId, $doctorUserId);

        $totalInvoiced = 0.0;
        $totalDiscount = 0.0;
        $approved = 0.0;
        $paid = 0.0;
        $caseBalance = 0.0;
        foreach ($caseIds as $caseId) {
            $caseSummary = $this->ledger->getSummary($caseId);
            $totalInvoiced += (float)$caseSummary['total_invoiced'];
            $totalDiscount += (float)$caseSummary['total_discount'];
            $approved += (float)$caseSummary['approved'];
            $paid += (float)$caseSummary['paid'];
            $caseBalance += (float)$caseSummary['balance'];
        }

        $pendingRequests = $this->pendingRequestsTotal($caseIds);
        $installmentRemaining = $this->installmentRemainingTotal($caseIds);

        $xrayOutstanding = $doctorUserId !== null
            ? $this->xrayManager->getPatientXrayDebtForDoctor($patientId, $doctorUserId)
            : $this->xrayManager->getPatientXrayDebt($patientId);

        // Credit is a patient-level resource (overpayment refunds), not owned
        // by any one doctor's folders — it is not part of the "folders/cases/
        // x-rays" doctor-scoped set, so it is read the same way regardless of
        // $doctorUserId.
        $credit = (float)$this->debtPayment->getPatientCredit($patientId);

        return [
            'total_invoiced'        => $totalInvoiced,
            'total_discount'        => $totalDiscount,
            'approved'              => $approved,
            'paid'                  => $paid,
            'pending_requests'      => $pendingRequests,
            'case_balance'          => $caseBalance,
            'xray_outstanding'      => (float)$xrayOutstanding,
            'credit'                => $credit,
            'installment_remaining' => $installmentRemaining,
            'net_debt'              => max(0.0, $caseBalance + (float)$xrayOutstanding - $credit),
        ];
    }

    // ================================================================
    // previousDebt()
    // ================================================================

    /**
     * قەرزی پێشوو — what the patient still owes the clinic APART from the
     * receipt being printed, so a visit receipt can carry the whole account
     * (cases/receipt.php). Clinic-wide on purpose: the patient owes the clinic,
     * not one doctor. Same figures as summary(): every approved invoice's
     * remaining_amount (AccountingLedger), x-ray debt, the pre-system opening
     * balance (PatientLedger), minus the patient's credit.
     *
     * $excludeInvoiceId — the receipt's own invoice, whose remaining is already
     * printed as «ماوە»; $excludeCaseId — the whole case, for a whole-case sheet.
     *
     * @return array ['cases'=>float,'xray'=>float,'opening'=>float,'credit'=>float,
     *   'total'=>float (never negative), 'invoices'=>int (unsettled receipts counted)]
     */
    public function previousDebt(int $patientId, ?int $excludeInvoiceId = null, ?int $excludeCaseId = null): array
    {
        // Every line is named, so the sheet can say exactly WHAT the earlier
        // debt is: which receipt (number, work day, doctor), an x-ray, the old
        // pre-system debt, or the credit taken off.
        $items = [];
        $cases = 0.0;
        $invoices = 0;
        foreach ($this->scopedCaseIds($patientId, null) as $caseId) {
            if ($excludeCaseId !== null && $caseId === $excludeCaseId) {
                continue;
            }
            foreach ($this->ledger->getInvoices($caseId, false) as $inv) {
                if (($inv['accounting_status'] ?? '') !== 'approved') {
                    continue;
                }
                if ($excludeInvoiceId !== null && (int)$inv['id'] === $excludeInvoiceId) {
                    continue;
                }
                $due = (float)($inv['remaining_amount'] ?? 0);
                if ($due > 0.009) {
                    $cases += $due;
                    $invoices++;
                    $items[] = [
                        'kind'   => 'invoice',
                        'label'  => 'پسوولە ' . (string)($inv['invoice_no'] ?? $inv['id']),
                        'date'   => !empty($inv['work_date']) ? date('Y/n/j', strtotime($inv['work_date'])) : '',
                        'detail' => trim((string)($inv['doctor_name'] ?? '')),
                        'amount' => $due,
                    ];
                }
            }
        }
        $xray = (float)$this->xrayManager->getPatientXrayDebt($patientId);
        if ($xray > 0.009) {
            $items[] = ['kind' => 'xray', 'label' => 'ئێکسڕەی', 'date' => '', 'detail' => '', 'amount' => $xray];
        }
        $opening = 0.0;
        if ($this->db->tableExists('patient_opening_balances')) {
            $row = $this->db->fetch('SELECT amount, as_of_date FROM patient_opening_balances WHERE patient_id = :id', ['id' => $patientId]);
            $opening = max(0.0, (float)($row['amount'] ?? 0));
            if ($opening > 0.009) {
                $items[] = [
                    'kind'   => 'opening',
                    'label'  => 'قەرزی کۆن (پێش سیستەم)',
                    'date'   => !empty($row['as_of_date']) ? date('Y/n/j', strtotime($row['as_of_date'])) : '',
                    'detail' => '',
                    'amount' => $opening,
                ];
            }
        }
        $credit = max(0.0, (float)$this->debtPayment->getPatientCredit($patientId));
        if ($credit > 0.009) {
            $items[] = ['kind' => 'credit', 'label' => 'ئەمانەتی نەخۆش (کەم دەکرێتەوە)', 'date' => '', 'detail' => '', 'amount' => -$credit];
        }
        return [
            'cases'    => $cases,
            'xray'     => $xray,
            'opening'  => $opening,
            'credit'   => $credit,
            'invoices' => $invoices,
            'items'    => $items,
            'total'    => max(0.0, $cases + $xray + $opening - $credit),
        ];
    }

    // ================================================================
    // statement()
    // ================================================================

    /**
     * @return array ['summary'=>..., 'cases'=>[per case: ['case'=>row,
     *   'invoices'=>decorated rows,'payments'=>rows+allocations,
     *   'installment'=>plan+schedule|null,'summary'=>getSummary]],
     *   'xray'=>['requests'=>rows,'debts'=>rows,'payments'=>rows],
     *   'credit'=>['balance'=>float,'log'=>rows],
     *   'activity'=>merged chronological rows]
     */
    public function statement(int $patientId, ?int $doctorUserId = null): array
    {
        $caseIds = $this->scopedCaseIds($patientId, $doctorUserId);
        $summary = $this->summary($patientId, $doctorUserId);

        $cases = [];
        $activity = [];
        foreach ($caseIds as $caseId) {
            $cases[] = [
                'case'        => $this->db->fetch('SELECT * FROM cases WHERE id = :id', ['id' => $caseId]),
                'invoices'    => $this->ledger->getInvoices($caseId),
                'payments'    => $this->ledger->getPayments($caseId),
                'installment' => $this->currentPlanSummary($caseId),
                'summary'     => $this->ledger->getSummary($caseId),
            ];
            foreach ($this->ledger->getActivity($caseId) as $row) {
                $activity[] = $row;
            }
        }

        foreach ($this->xrayHistory($patientId, $doctorUserId) as $row) {
            $activity[] = $row;
        }
        usort($activity, function ($a, $b) {
            return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
        });

        return [
            'summary' => $summary,
            'cases'   => $cases,
            'xray'    => [
                'requests' => $this->xrayRequests($patientId, $doctorUserId),
                'debts'    => $this->xrayDebts($patientId, $doctorUserId),
                'payments' => $this->xrayDebtPayments($patientId, $doctorUserId),
            ],
            'credit' => [
                'balance' => $summary['credit'],
                'log'     => $this->db->fetchAll(
                    'SELECT * FROM patient_credit_logs WHERE patient_id = :id ORDER BY created_at DESC, id DESC',
                    ['id' => $patientId]
                ),
            ],
            'activity' => $activity,
        ];
    }

    // ================================================================
    // Internal helpers — every helper below either resolves ids/scope or
    // sums a value a service already computed; no rate/formula is derived
    // here (spec §9).
    // ================================================================

    /** patients -> patient_folders -> cases, doctor-scoped when requested. */
    private function scopedCaseIds(int $patientId, ?int $doctorUserId): array
    {
        if ($doctorUserId !== null) {
            $rows = $this->db->fetchAll(
                "SELECT c.id FROM cases c
                 JOIN patient_folders pf ON pf.id = c.folder_id
                 WHERE pf.patient_id = :patient_id AND pf.doctor_id = :doctor_id",
                ['patient_id' => $patientId, 'doctor_id' => $doctorUserId]
            );
        } else {
            $rows = $this->db->fetchAll(
                "SELECT c.id FROM cases c
                 JOIN patient_folders pf ON pf.id = c.folder_id
                 WHERE pf.patient_id = :patient_id",
                ['patient_id' => $patientId]
            );
        }
        return array_map('intval', array_column($rows, 'id'));
    }

    /** SUM(payments.amount) pending over the given (already-scoped) cases. */
    private function pendingRequestsTotal(array $caseIds): float
    {
        if (!$caseIds) {
            return 0.0;
        }
        $ids = implode(',', array_map('intval', $caseIds));
        $row = $this->db->fetch(
            "SELECT COALESCE(SUM(amount),0) AS amount FROM payments
             WHERE case_id IN ({$ids}) AND status = 'pending'"
        );
        return (float)($row['amount'] ?? 0);
    }

    /** Sum of Installment::getPlanSummary()['remaining_balance'] over ACTIVE
     *  plans only on the given cases — pending plans count nowhere in totals,
     *  they only appear (labeled) in the statement body. */
    private function installmentRemainingTotal(array $caseIds): float
    {
        if (!$caseIds) {
            return 0.0;
        }
        $ids = implode(',', array_map('intval', $caseIds));
        $plans = $this->db->fetchAll(
            "SELECT id FROM installment_plans WHERE status = 'active' AND case_id IN ({$ids})"
        );
        $total = 0.0;
        foreach ($plans as $plan) {
            $planSummary = $this->installment->getPlanSummary((int)$plan['id']);
            if ($planSummary) {
                $total += (float)$planSummary['remaining_balance'];
            }
        }
        return $total;
    }

    /**
     * The one plan (pending OR active) currently attached to a case, with its
     * schedule. Installment::getPlanByCase() is deliberately active-only
     * (Installment.php:414-420) and would hide a still-pending proposal that
     * is nonetheless part of the patient's financial picture — the statement
     * fetches it separately and labels it via its own 'status' field.
     */
    private function currentPlanSummary(int $caseId)
    {
        $plan = $this->db->fetch(
            "SELECT id FROM installment_plans
             WHERE case_id = :case_id AND status IN ('pending','active')
             ORDER BY created_at DESC LIMIT 1",
            ['case_id' => $caseId]
        );
        if (!$plan) {
            return null;
        }
        return $this->installment->getPlanSummary((int)$plan['id']);
    }

    /** The patient's xray_requests, all payment types, chronological. */
    private function xrayRequests(int $patientId, ?int $doctorUserId): array
    {
        if ($doctorUserId !== null) {
            return $this->db->fetchAll(
                "SELECT * FROM xray_requests
                 WHERE patient_id = :patient_id AND requested_by = :doctor_id
                 ORDER BY created_at ASC, id ASC",
                ['patient_id' => $patientId, 'doctor_id' => $doctorUserId]
            );
        }
        return $this->db->fetchAll(
            "SELECT * FROM xray_requests WHERE patient_id = :patient_id ORDER BY created_at ASC, id ASC",
            ['patient_id' => $patientId]
        );
    }

    private function xrayDebts(int $patientId, ?int $doctorUserId): array
    {
        if ($doctorUserId !== null) {
            return $this->db->fetchAll(
                "SELECT pxd.* FROM patient_xray_debts pxd
                 JOIN xray_requests xr ON xr.id = pxd.request_id
                 WHERE pxd.patient_id = :patient_id AND xr.requested_by = :doctor_id
                 ORDER BY pxd.created_at DESC",
                ['patient_id' => $patientId, 'doctor_id' => $doctorUserId]
            );
        }
        return $this->db->fetchAll(
            "SELECT * FROM patient_xray_debts WHERE patient_id = :patient_id ORDER BY created_at DESC",
            ['patient_id' => $patientId]
        );
    }

    private function xrayDebtPayments(int $patientId, ?int $doctorUserId): array
    {
        if ($doctorUserId !== null) {
            return $this->db->fetchAll(
                "SELECT xdp.* FROM xray_debt_payments xdp
                 JOIN patient_xray_debts pxd ON pxd.id = xdp.debt_id
                 JOIN xray_requests xr ON xr.id = pxd.request_id
                 WHERE pxd.patient_id = :patient_id AND xr.requested_by = :doctor_id
                 ORDER BY xdp.created_at DESC",
                ['patient_id' => $patientId, 'doctor_id' => $doctorUserId]
            );
        }
        return $this->db->fetchAll(
            "SELECT xdp.* FROM xray_debt_payments xdp
             JOIN patient_xray_debts pxd ON pxd.id = xdp.debt_id
             WHERE pxd.patient_id = :patient_id
             ORDER BY xdp.created_at DESC",
            ['patient_id' => $patientId]
        );
    }

    /** xray_request_history for the patient's own requests, merged into the
     *  activity timeline (raw rows — each already carries created_at). */
    private function xrayHistory(int $patientId, ?int $doctorUserId): array
    {
        if ($doctorUserId !== null) {
            return $this->db->fetchAll(
                "SELECT xrh.* FROM xray_request_history xrh
                 JOIN xray_requests xr ON xr.id = xrh.request_id
                 WHERE xr.patient_id = :patient_id AND xr.requested_by = :doctor_id",
                ['patient_id' => $patientId, 'doctor_id' => $doctorUserId]
            );
        }
        return $this->db->fetchAll(
            "SELECT xrh.* FROM xray_request_history xrh
             JOIN xray_requests xr ON xr.id = xrh.request_id
             WHERE xr.patient_id = :patient_id",
            ['patient_id' => $patientId]
        );
    }
}
