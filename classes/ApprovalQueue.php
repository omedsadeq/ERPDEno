<?php
/**
 * ApprovalQueue — pure read-only views over the real approval-state columns.
 *
 * The accounting "approval center" has five work queues, one per real
 * pending state:
 *   - invoices     case_invoices.accounting_status = 'sent'
 *   - payments     payments.status = 'pending'
 *   - installments installment_plans.status = 'pending'
 *   - xray         xray_requests.request_status IN ('pending_accountant','returned_to_accountant')
 *   - station      waiting_room.status = 'at_accounting' (today), deduped
 *                  against the other open queues so a patient never shows
 *                  twice while their real request is already visible.
 *
 * This class NEVER writes and NEVER transitions state — every row here is
 * derived straight from CaseInvoice::approve()/returnForCorrection(),
 * Payment::approve()/reject(), Installment::activatePlan()/rejectPlan() and
 * XrayManager's own workflow. Approving/rejecting empties a queue row purely
 * because the underlying status column changed; this class just re-reads it.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/AccountingLedger.php';
require_once __DIR__ . '/Installment.php';
require_once __DIR__ . '/CaseInvoice.php';
require_once __DIR__ . '/XrayManager.php';
require_once __DIR__ . '/PatientFinance.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/Payment.php';
require_once __DIR__ . '/PatientAdvance.php';

class ApprovalQueue
{
    /**
     * The جۆر values the Approval Center's kind combobox may ask for — the
     * same six sources the one feed merges, and the same six badges the جۆر
     * column shows. Anything else is treated as "no filter".
     */
    public const KINDS = ['invoice', 'archwork', 'payment', 'installment', 'xray', 'station'];

    private $db;

    /**
     * Per-request memo for counts(). header.php, sidebar.php, and the
     * Approval Center page itself each construct their own fresh
     * ApprovalQueue and call counts() once per accountant-facing page load
     * (up to 3x) — without this, that's all 5 underlying COUNT queries
     * run up to 3 times (up to 15 queries for one page render), bypassing
     * the exact same badge-duplication problem Database::count()'s own
     * $countMemo (Database.php:165-195) already solves for simple counts.
     * Deliberately a STATIC property (not per-instance): each of those
     * three call sites builds its own `new ApprovalQueue()`, so an
     * instance-level memo would never be shared between them and would
     * accomplish nothing.
     *
     * Default behaviour trusts the memo (matches every real page's
     * read-only "show me the badge" use); pass $fresh=true to force a
     * live recompute — needed whenever a write happens between two
     * counts() calls in the SAME request/process, which is otherwise
     * invisible to a static cache. In production each HTTP request is its
     * own PHP process (no persistent workers in this app), so the memo
     * can never outlive one page render; the one place a write-then-
     * reread pattern actually occurs today is tests/cases/ApprovalQueueTest.php
     * (one long-lived CLI process reusing one ApprovalQueue instance
     * across a mutation), which passes $fresh=true for its post-mutation
     * assertions. api/approval-center.php's polling actions also pass
     * $fresh=true defensively, since that's the most latency-sensitive
     * read path.
     */
    private static $countsMemo = null;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ================================================================
    // Counts
    // ================================================================

    /**
     * @param bool $fresh bypass the per-request memo and recompute live.
     * @return array ['all'=>int,'invoices'=>int,'payments'=>int,
     *                'installments'=>int,'xray'=>int,'station'=>int,'badge'=>int]
     *   badge = invoices+payments+installments+xray (station excluded —
     *   presence at the station is not itself an approval decision).
     */
    public function counts(bool $fresh = false): array
    {
        if (!$fresh && self::$countsMemo !== null) {
            return self::$countsMemo;
        }

        $invoices = (int)($this->db->fetch(
            "SELECT COUNT(*) c FROM case_invoices WHERE accounting_status = 'sent'"
        )['c'] ?? 0);

        $payments = (int)($this->db->fetch(
            "SELECT COUNT(*) c FROM payments WHERE status = 'pending'"
        )['c'] ?? 0);

        $installments = (int)($this->db->fetch(
            "SELECT COUNT(*) c FROM installment_plans WHERE status = 'pending'"
        )['c'] ?? 0);

        $xray = (int)($this->db->fetch(
            "SELECT COUNT(*) c FROM xray_requests
             WHERE request_status IN ('pending_accountant','returned_to_accountant')"
        )['c'] ?? 0);

        // Arch work receipts (وەسڵی کارە ئەنجامدراوەکان). Counted with the
        // invoices, because to the accountant they are the same job: a document
        // the doctor sent that needs a decision. They only exist once the
        // 2026_09_09 migration has run.
        $archWork = $this->db->tableExists('arch_work_receipts')
            ? (int)($this->db->fetch("SELECT COUNT(*) c FROM arch_work_receipts WHERE status = 'sent'")['c'] ?? 0)
            : 0;

        $station = (int)($this->db->fetch(
            "SELECT COUNT(*) c FROM waiting_room wr
             WHERE wr.status = 'at_accounting' AND wr.check_in_time >= CURDATE()
             {$this->stationDedupeSql()}"
        )['c'] ?? 0);

        self::$countsMemo = [
            'all'          => $invoices + $archWork + $payments + $installments + $xray + $station,
            'invoices'     => $invoices + $archWork,
            'archwork'     => $archWork,
            'payments'     => $payments,
            'installments' => $installments,
            'xray'         => $xray,
            'station'      => $station,
            'badge'        => $invoices + $archWork + $payments + $installments + $xray,
        ];
        return self::$countsMemo;
    }

    /**
     * The station queue de-dupes against the OTHER open queues so a patient
     * already visible as an invoice/payment/x-ray request never also shows
     * as a bare station row. accounting_reports (the old case-report table
     * the feed used to de-dup against) is being frozen by this refactor, so
     * this checks the same structural intent — "does this patient already
     * have an open, richer row elsewhere?" — against the three live queues
     * instead.
     */
    private function stationDedupeSql(): string
    {
        return "
            AND NOT EXISTS (
                SELECT 1 FROM case_invoices ci2
                JOIN cases c2 ON c2.id = ci2.case_id
                JOIN patient_folders pf2 ON pf2.id = c2.folder_id
                WHERE pf2.patient_id = wr.patient_id AND ci2.accounting_status = 'sent'
            )
            AND NOT EXISTS (
                SELECT 1 FROM payments pay2
                JOIN cases c3 ON c3.id = pay2.case_id
                JOIN patient_folders pf3 ON pf3.id = c3.folder_id
                WHERE pf3.patient_id = wr.patient_id AND pay2.status = 'pending'
            )
            AND NOT EXISTS (
                SELECT 1 FROM xray_requests xr2
                WHERE xr2.patient_id = wr.patient_id
                  AND xr2.request_status IN ('pending_accountant','returned_to_accountant')
            )";
    }

    // ================================================================
    // List
    // ================================================================

    /**
     * @param string $tab all|invoices|payments|installments|xray|station|history|approved
     * @param array  $filters q, doctor_id, date_from, date_to
     * @return array rows: ['kind','id','uid','patient_id','patient_name',
     *   'doctor_name','number','amount','payment_type','intent',
     *   'promised_date','promised_amount','created_at','status','action']
     */
    public function list(string $tab, array $filters = []): array
    {
        switch ($tab) {
            case 'all':          return $this->listAll($filters);
            case 'invoices':     return array_merge($this->listInvoices($filters), $this->listArchWork($filters));
            case 'payments':     return $this->listPayments($filters);
            case 'installments': return $this->listInstallments($filters);
            case 'xray':         return $this->listXray($filters);
            case 'station':      return $this->listStation($filters);
            case 'history':      return $this->listHistory($filters);
            case 'approved':     return $this->listApproved($filters);
            case 'advances':     return $this->listAdvances($filters);
            default:             return [];
        }
    }

    /**
     * Every live request in one feed, NEWEST FIRST by default — the newest
     * thing to arrive is what the accountant is being asked about, so it
     * belongs at the top of the screen rather than at the bottom of a long
     * list. The screen's ⇅ button flips it (filters['sort'] = 'asc').
     *
     * The sort is global (not per source), so the client's per-patient grouping
     * still holds: a patient's group appears at the position of their newest
     * outstanding request, and the requests inside it run newest to oldest too.
     * The decided views (history / پەسەندکراو) already read this way.
     */
    private function listAll(array $filters): array
    {
        $rows = array_merge(
            $this->wantsKind($filters, 'invoice')     ? $this->listInvoices($filters)     : [],
            $this->wantsKind($filters, 'archwork')    ? $this->listArchWork($filters)     : [],
            $this->wantsKind($filters, 'payment')     ? $this->listPayments($filters)     : [],
            $this->wantsKind($filters, 'installment') ? $this->listInstallments($filters) : [],
            $this->wantsKind($filters, 'xray')        ? $this->listXray($filters)         : [],
            $this->wantsKind($filters, 'station')     ? $this->listStation($filters)      : []
        );
        $kindOrder = ['invoice' => 0, 'archwork' => 0, 'payment' => 1, 'installment' => 2, 'xray' => 3, 'station' => 4];
        $asc = self::dir($filters) === 'ASC';
        usort($rows, static function (array $a, array $b) use ($kindOrder, $asc): int {
            // Time first, in the asked-for direction. Two requests filed in the
            // same second fall back to kind (invoices before payments before
            // …), then to id in the same direction as the time.
            $left  = (string)(($asc ? $a : $b)['created_at'] ?? '');
            $right = (string)(($asc ? $b : $a)['created_at'] ?? '');
            $byTime = strcmp($left, $right);
            if ($byTime !== 0) return $byTime;
            $byKind = ($kindOrder[$a['kind']] ?? 99) <=> ($kindOrder[$b['kind']] ?? 99);
            if ($byKind !== 0) return $byKind;
            return $asc
                ? (((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0)))
                : (((int)($b['id'] ?? 0)) <=> ((int)($a['id'] ?? 0)));
        });
        return $rows;
    }

    /**
     * قەرزی کۆن — counter receipts taken before any case existed to bill them
     * to (patient_advances). Its own tab rather than a row in the main feed:
     * these are not requests waiting for a decision, they are money already
     * taken, and what the accountant wants from this list is "who paid at the
     * counter, and how much of it is still sitting unspent".
     *
     * `amount` is what was taken; `remaining_amount` is what the doctor has not
     * yet put on a case, which is what the row is really about. Newest first by
     * default — the morning's receipts are the ones anyone is asking about —
     * and the ⇅ button can turn it round.
     *
     * Only receipts that still hold money nobody has placed on a case are
     * listed (دانەنراوە, and بەشی دانراوە for what is left of one). Fully
     * placed or refunded receipts need nothing more from anyone; they stay on
     * the patient's statement. Filtered in SQL so LIMIT 200 counts only these.
     */
    private function listAdvances(array $filters): array
    {
        if (!$this->db->tableExists('patient_advances')) {
            return [];
        }
        // a.doctor_id exists from 2026_09_27_patient_advance_doctor onwards; the
        // دکتۆر combobox can narrow these rows by it now that reception picks
        // one when the money is taken.
        [$filterSql, $params] = $this->filterSql($filters, 'a.advance_no', 'a.doctor_id', 'a.received_at');
        $dir = self::dir($filters);
        $rows = $this->db->fetchAll(
            "SELECT a.id, a.advance_no, a.amount, a.refunded_amount, a.received_at,
                    a.payment_method, a.notes,
                    COALESCE(al.used,0) AS used,
                    p.id AS patient_id, p.full_name AS patient_name, p.phone AS patient_phone,
                    u.full_name AS received_by_name,
                    doc.full_name AS doctor_name
             FROM patient_advances a
             JOIN patients p ON p.id = a.patient_id
             LEFT JOIN users u ON u.id = a.received_by
             LEFT JOIN users doc ON doc.id = a.doctor_id
             LEFT JOIN (
                SELECT advance_id, SUM(amount) AS used
                FROM patient_advance_allocations
                WHERE reversed_at IS NULL
                GROUP BY advance_id
             ) al ON al.advance_id = a.id
             WHERE a.amount - a.refunded_amount - COALESCE(al.used,0) > 0.009 {$filterSql}
             ORDER BY a.received_at {$dir}, a.id {$dir}
             LIMIT 200",
            $params
        );

        $out = [];
        foreach ($rows as $r) {
            $remaining = max(0, (float)$r['amount'] - (float)$r['refunded_amount'] - (float)$r['used']);
            // The chip the client draws is keyed off this, so it says at a
            // glance whether the doctor still has to do something with it.
            if ((float)$r['refunded_amount'] > 0.009 && $remaining <= 0.009) {
                $status = 'refunded';
            } elseif ($remaining <= 0.009) {
                $status = 'allocated';
            } elseif ((float)$r['used'] > 0.009) {
                $status = 'partial';
            } else {
                $status = 'open';
            }
            $out[] = [
                'kind' => 'advance', 'id' => (int)$r['id'], 'uid' => 'advance:' . $r['id'],
                'patient_id' => (int)$r['patient_id'], 'patient_name' => $r['patient_name'],
                'patient_phone' => $r['patient_phone'],
                // The doctor reception picked when they took the money; an older
                // receipt has none, so it falls back to who took it, which is
                // what this column showed for every row before.
                'doctor_name' => $r['doctor_name'] ?: $r['received_by_name'],
                'received_by_name' => $r['received_by_name'],
                'number' => $r['advance_no'],
                'amount' => (float)$r['amount'],
                'remaining_amount' => $remaining,
                'used_amount' => (float)$r['used'],
                'refunded_amount' => (float)$r['refunded_amount'],
                'payment_type' => $r['payment_method'],
                'intent' => null, 'promised_date' => null, 'promised_amount' => null,
                'created_at' => $r['received_at'], 'status' => $status, 'action' => null,
            ];
        }
        return $out;
    }

    /**
     * The جۆر combobox narrows the merged feed to one source. It is applied by
     * SKIPPING the other sources' queries rather than by filtering the merged
     * array afterwards: mergeDecided() caps its result at 200 rows, so a
     * post-filter would silently drop the very rows the filter asked for
     * whenever a busier source crowded the window.
     *
     * An unknown or empty value means "every kind", so a hand-edited ?kind=
     * can only ever widen the list back to the default, never leak rows.
     */
    private function wantsKind(array $filters, string $kind): bool
    {
        $want = (string)($filters['kind'] ?? '');
        return $want === '' || !in_array($want, self::KINDS, true) || $want === $kind;
    }

    /**
     * The ⇅ نوێ↔کۆن button's direction, for the ORDER BY of every source.
     * Newest first unless the screen explicitly asks for the other way, and
     * whitelisted to the two keywords because it is interpolated, not bound.
     *
     * It has to be applied in SQL rather than by reversing the array in the
     * browser: mergeDecided() keeps only 200 rows, so reversing that window
     * would show the oldest of the NEWEST 200 — not the oldest rows there are.
     */
    private static function dir(array $filters): string
    {
        return (($filters['sort'] ?? '') === 'asc') ? 'ASC' : 'DESC';
    }

    /**
     * Shared q/doctor_id/date_from/date_to filter builder. Always three
     * SEPARATE `?` placeholders for q (PDO emulation is off — a named
     * placeholder cannot be bound twice) — the name/phone/number match never
     * reuses one param across positions.
     *
     * @return array [string $sql, array $params] — $sql is appended (it
     *   already starts with " AND ...") straight after the tab's base WHERE.
     */
    private function filterSql(array $filters, string $numberExpr, ?string $doctorCol, string $dateExpr): array
    {
        $sql = '';
        $params = [];

        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . $q . '%';
            $sql .= " AND (p.full_name LIKE ? OR p.phone LIKE ? OR ({$numberExpr} COLLATE utf8mb4_unicode_ci) LIKE ?)";
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        if ($doctorCol !== null && !empty($filters['doctor_id'])) {
            $sql .= " AND {$doctorCol} = ?";
            $params[] = (int)$filters['doctor_id'];
        }
        if (!empty($filters['date_from'])) {
            $sql .= " AND DATE({$dateExpr}) >= ?";
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $sql .= " AND DATE({$dateExpr}) <= ?";
            $params[] = $filters['date_to'];
        }
        return [$sql, $params];
    }

    // ---------------------------------------------------------- invoices

    private function listInvoices(array $filters): array
    {
        [$filterSql, $params] = $this->filterSql(
            $filters, 'ci.invoice_no', 'ci.doctor_id', 'COALESCE(ci.sent_at, ci.created_at)'
        );
        $dir = self::dir($filters);
        $rows = $this->db->fetchAll(
            "SELECT ci.id, ci.invoice_no, ci.total_amount, ci.discount_amount, ci.draft_received_amount, ci.prior_paid_amount, ci.payment_intent,
                    ci.promised_date, ci.promised_amount, ci.accounting_status,
                    COALESCE(ci.sent_at, ci.created_at) AS ts,
                    p.id AS patient_id, p.full_name AS patient_name, p.phone AS patient_phone,
                    COALESCE(doc.full_name, sender.full_name) AS doctor_name,
                    CASE WHEN ci.created_by IS NOT NULL AND ci.created_by <> COALESCE(ci.doctor_id, 0)
                         THEN maker.full_name END AS made_by_name
             FROM case_invoices ci
             JOIN cases c ON c.id = ci.case_id
             JOIN patient_folders pf ON pf.id = c.folder_id
             JOIN patients p ON p.id = pf.patient_id
             LEFT JOIN users doc ON doc.id = ci.doctor_id
             LEFT JOIN users sender ON sender.id = ci.sent_by
             LEFT JOIN users maker ON maker.id = ci.created_by
             WHERE ci.accounting_status = 'sent'{$filterSql}
             ORDER BY ts {$dir}, ci.id {$dir}",
            $params
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'kind' => 'invoice', 'id' => (int)$r['id'], 'uid' => 'invoice:' . $r['id'],
                'patient_id' => (int)$r['patient_id'], 'patient_name' => $r['patient_name'],
                'patient_phone' => $r['patient_phone'],
                'doctor_name' => $r['doctor_name'], 'number' => $r['invoice_no'],
                // Made by someone else for this doctor (an admin, say) — «✍ …».
                'made_by_name' => $r['made_by_name'],
                'amount' => max(0, (float)$r['total_amount'] - (float)$r['discount_amount']),
                'received_amount' => min(
                    max(0, (float)$r['total_amount'] - (float)$r['discount_amount']),
                    max(0, (float)$r['draft_received_amount'])
                ),
                // Historical cases: paid before the system — not cash, shown apart.
                'prior_paid_amount' => max(0, (float)($r['prior_paid_amount'] ?? 0)),
                'payment_type' => null, 'intent' => $r['payment_intent'],
                'promised_date' => $r['promised_date'],
                'promised_amount' => $r['promised_amount'] !== null ? (float)$r['promised_amount'] : null,
                'created_at' => $r['ts'], 'status' => $r['accounting_status'],
                'action' => 'پەسەندکردن / گەڕاندنەوە',
            ];
        }
        return $out;
    }

    // --------------------------------------------------------- arch work

    /**
     * وەسڵی کارە ئەنجامدراوەکان awaiting the accountant. It has no amount of
     * its own — an arch visit adds no charge — so `amount` is null and the row
     * reads as a work document, not a bill.
     */
    private function listArchWork(array $filters): array
    {
        if (!$this->db->tableExists('arch_work_receipts')) {
            return [];
        }
        [$filterSql, $params] = $this->filterSql(
            $filters, 'r.receipt_no', 'pf.doctor_id', 'COALESCE(r.sent_at, r.created_at)'
        );
        $dir = self::dir($filters);
        $rows = $this->db->fetchAll(
            "SELECT r.id, r.receipt_no, r.status,
                    COALESCE(r.sent_at, r.created_at) AS ts,
                    (SELECT COUNT(*) FROM arch_procedure_logs l WHERE l.work_receipt_id = r.id) AS work_count,
                    p.id AS patient_id, p.full_name AS patient_name, p.phone AS patient_phone,
                    COALESCE(sender.full_name, doc.full_name) AS doctor_name
             FROM arch_work_receipts r
             JOIN cases c ON c.id = r.case_id
             JOIN patient_folders pf ON pf.id = c.folder_id
             JOIN patients p ON p.id = pf.patient_id
             LEFT JOIN users sender ON sender.id = r.sent_by
             LEFT JOIN users doc ON doc.id = pf.doctor_id
             WHERE r.status = 'sent'{$filterSql}
             ORDER BY ts {$dir}, r.id {$dir}",
            $params
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'kind' => 'archwork', 'id' => (int)$r['id'], 'uid' => 'archwork:' . $r['id'],
                'patient_id' => (int)$r['patient_id'], 'patient_name' => $r['patient_name'],
                'patient_phone' => $r['patient_phone'],
                'doctor_name' => $r['doctor_name'], 'number' => $r['receipt_no'],
                'amount' => null, 'received_amount' => null,
                'work_count' => (int)$r['work_count'],
                'payment_type' => null, 'intent' => null,
                'promised_date' => null, 'promised_amount' => null,
                'created_at' => $r['ts'], 'status' => $r['status'],
                'action' => 'کارە ئەنجامدراوەکان',
            ];
        }
        return $out;
    }

    // ---------------------------------------------------------- payments

    private function listPayments(array $filters): array
    {
        [$filterSql, $params] = $this->filterSql($filters, 'pay.payment_number', 'pay.received_by', 'pay.payment_date');
        $dir = self::dir($filters);
        $rows = $this->db->fetchAll(
            "SELECT pay.id, pay.payment_number, pay.amount, pay.discount_amount, pay.payment_type, pay.status,
                    pay.payment_date AS ts,
                    p.id AS patient_id, p.full_name AS patient_name, p.phone AS patient_phone,
                    doc.full_name AS doctor_name
             FROM payments pay
             JOIN cases c ON c.id = pay.case_id
             JOIN patient_folders pf ON pf.id = c.folder_id
             JOIN patients p ON p.id = pf.patient_id
             LEFT JOIN users doc ON doc.id = pay.received_by
             WHERE pay.status = 'pending'{$filterSql}
             ORDER BY ts {$dir}, pay.id {$dir}",
            $params
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'kind' => 'payment', 'id' => (int)$r['id'], 'uid' => 'payment:' . $r['id'],
                'patient_id' => (int)$r['patient_id'], 'patient_name' => $r['patient_name'],
                'patient_phone' => $r['patient_phone'],
                'doctor_name' => $r['doctor_name'], 'number' => $r['payment_number'],
                'amount' => (float)$r['amount'], 'payment_type' => $r['payment_type'],
                'intent' => null, 'promised_date' => null, 'promised_amount' => null,
                'created_at' => $r['ts'], 'status' => $r['status'],
                'action' => (float)$r['discount_amount'] > 0
                    ? 'پارە و داشکاندن ' . number_format((float)$r['discount_amount'])
                    : 'پەسەندکردن / ڕەتکردنەوە',
            ];
        }
        return $out;
    }

    // ------------------------------------------------------ installments

    private function listInstallments(array $filters): array
    {
        [$filterSql, $params] = $this->filterSql($filters, "CONCAT('P-', ip.id)", 'ip.created_by', 'ip.created_at');
        $dir = self::dir($filters);
        $rows = $this->db->fetchAll(
            "SELECT ip.id, ip.total_amount, ip.status, ip.created_at AS ts,
                    p.id AS patient_id, p.full_name AS patient_name, p.phone AS patient_phone,
                    doc.full_name AS doctor_name
             FROM installment_plans ip
             JOIN cases c ON c.id = ip.case_id
             JOIN patient_folders pf ON pf.id = c.folder_id
             JOIN patients p ON p.id = pf.patient_id
             LEFT JOIN users doc ON doc.id = ip.created_by
             WHERE ip.status = 'pending'{$filterSql}
             ORDER BY ts {$dir}, ip.id {$dir}",
            $params
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'kind' => 'installment', 'id' => (int)$r['id'], 'uid' => 'installment:' . $r['id'],
                'patient_id' => (int)$r['patient_id'], 'patient_name' => $r['patient_name'],
                'patient_phone' => $r['patient_phone'],
                'doctor_name' => $r['doctor_name'], 'number' => 'P-' . $r['id'],
                'amount' => (float)$r['total_amount'], 'payment_type' => null,
                'intent' => null, 'promised_date' => null, 'promised_amount' => null,
                'created_at' => $r['ts'], 'status' => $r['status'],
                'action' => 'چالاککردن / ڕەتکردنەوە',
            ];
        }
        return $out;
    }

    // -------------------------------------------------------------- xray

    private function listXray(array $filters): array
    {
        [$filterSql, $params] = $this->filterSql($filters, "CONCAT('X-', xr.id)", 'xr.requested_by', 'xr.created_at');
        $dir = self::dir($filters);
        $rows = $this->db->fetchAll(
            "SELECT xr.id, xr.amount, xr.final_amount, xr.payment_type, xr.request_status,
                    xr.created_at AS ts,
                    p.id AS patient_id, p.full_name AS patient_name, p.phone AS patient_phone,
                    -- An outside referring doctor owns the name here, not the
                    -- staff member in requested_by. See the 2026_09_26 migration.
                    COALESCE(xed.name, u.full_name) AS doctor_name,
                    xed.name AS external_doctor_name
             FROM xray_requests xr
             JOIN patients p ON p.id = xr.patient_id
             LEFT JOIN users u ON u.id = xr.requested_by
             LEFT JOIN xray_external_doctors xed ON xed.id = xr.external_doctor_id
             WHERE xr.request_status IN ('pending_accountant','returned_to_accountant'){$filterSql}
             ORDER BY ts {$dir}, xr.id {$dir}",
            $params
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'kind' => 'xray', 'id' => (int)$r['id'], 'uid' => 'xray:' . $r['id'],
                'patient_id' => (int)$r['patient_id'], 'patient_name' => $r['patient_name'],
                'patient_phone' => $r['patient_phone'],
                'doctor_name' => $r['doctor_name'], 'number' => 'X-' . $r['id'],
                'external_doctor_name' => $r['external_doctor_name'],
                'amount' => (float)($r['final_amount'] ?: $r['amount']),
                'payment_type' => $r['payment_type'], 'intent' => null,
                'promised_date' => null, 'promised_amount' => null,
                'created_at' => $r['ts'], 'status' => $r['request_status'],
                'action' => 'پەسەندکردنی پارە / قەرز',
            ];
        }
        return $out;
    }

    // ----------------------------------------------------------- station

    /**
     * Copied from api/accountant-feed.php's "patients sent to the accounting
     * station" block (verbatim structure), minus the dead station.php link
     * and with the de-dup rewritten onto the live invoices/payments/xray
     * queues per stationDedupeSql() above.
     */
    private function listStation(array $filters): array
    {
        $where = "wr.status = 'at_accounting' AND wr.check_in_time >= CURDATE()" . $this->stationDedupeSql();
        $params = [];

        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . $q . '%';
            $where .= " AND (p.full_name LIKE ? OR p.phone LIKE ? OR du.full_name LIKE ?)";
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        if (!empty($filters['doctor_id'])) {
            $where .= " AND wr.doctor_id = ?";
            $params[] = (int)$filters['doctor_id'];
        }
        if (!empty($filters['date_from'])) {
            $where .= " AND DATE(COALESCE(vfl.created_at, wr.check_in_time)) >= ?";
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where .= " AND DATE(COALESCE(vfl.created_at, wr.check_in_time)) <= ?";
            $params[] = $filters['date_to'];
        }

        // Arrival time is the only thing to sort on — the copied query carries
        // no separate "urgent" flag — so it follows the ⇅ button like the rest.
        $dir = self::dir($filters);
        $rows = $this->db->fetchAll(
            "SELECT wr.id, wr.patient_id, wr.status AS wr_status,
                    COALESCE(vfl.created_at, wr.check_in_time) AS moved_at,
                    p.full_name AS patient_name, p.phone AS patient_phone,
                    du.full_name AS doctor_name
             FROM waiting_room wr
             JOIN patients p ON wr.patient_id = p.id
             LEFT JOIN users du ON wr.doctor_id = du.id
             LEFT JOIN visit_flow_log vfl ON vfl.id = (
                 SELECT MAX(v2.id) FROM visit_flow_log v2
                 WHERE v2.waiting_room_id = wr.id AND v2.to_status = 'at_accounting'
             )
             LEFT JOIN users mu ON vfl.moved_by = mu.id
             WHERE {$where}
             ORDER BY moved_at {$dir}",
            $params
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'kind' => 'station', 'id' => (int)$r['id'], 'uid' => 'station:' . $r['id'],
                'patient_id' => (int)$r['patient_id'], 'patient_name' => $r['patient_name'],
                'patient_phone' => $r['patient_phone'],
                'doctor_name' => $r['doctor_name'], 'number' => null, 'amount' => null,
                'payment_type' => null, 'intent' => null, 'promised_date' => null, 'promised_amount' => null,
                'created_at' => $r['moved_at'], 'status' => $r['wr_status'],
                'action' => 'وەرگرتن',
            ];
        }
        return $out;
    }

    // ----------------------------------------------------------- history

    /**
     * Per-source rows acted on in the last 30 days, merged newest first.
     * list('history') reads from MULTIPLE sources at once — the four approval
     * queues' own "already decided" rows, whatever the decision was.
     */
    private function listHistory(array $filters): array
    {
        return $this->mergeDecided($filters, false);
    }

    /**
     * پەسەندکراو — the same decided rows, narrowed to the ones the accountant
     * actually APPROVED (returned/rejected/refunded/cancelled are excluded).
     * This is the second tab of the Approval Center: "هەموو داواکاری" is what
     * still needs a decision, this is what has already been granted one.
     */
    private function listApproved(array $filters): array
    {
        return $this->mergeDecided($filters, true);
    }

    /** @param bool $approvedOnly keep only approved/active rows per source. */
    private function mergeDecided(array $filters, bool $approvedOnly): array
    {
        // 🤖 خۆکار / ✋ دەستی (پەسەندکراو tab only). Only invoices are ever
        // approved by the nightly run, so «خۆکار» leaves the other sources out.
        if (!$approvedOnly) {
            $filters['approval'] = '';
        }
        $autoOnly = ($filters['approval'] ?? '') === 'auto';
        // No station source here: a waiting-room row is never "decided", so
        // picking ژووری چاوەڕوانی in the جۆر box legitimately empties these
        // two views.
        $rows = array_merge(
            $this->wantsKind($filters, 'invoice')     ? $this->historyInvoices($filters, $approvedOnly)     : [],
            !$autoOnly && $this->wantsKind($filters, 'archwork')    ? $this->historyArchWork($filters, $approvedOnly)     : [],
            !$autoOnly && $this->wantsKind($filters, 'payment')     ? $this->historyPayments($filters, $approvedOnly)     : [],
            !$autoOnly && $this->wantsKind($filters, 'installment') ? $this->historyInstallments($filters, $approvedOnly) : [],
            !$autoOnly && $this->wantsKind($filters, 'xray')        ? $this->historyXray($filters, $approvedOnly)         : []
        );
        // Each source already came back in the requested direction; this only
        // merges them. The 200-row cap then keeps the right end of the window:
        // the newest 200 when reading new→old, the oldest 200 when reading
        // old→new — which is why the direction is never applied in the browser.
        $asc = self::dir($filters) === 'ASC';
        usort($rows, static function (array $a, array $b) use ($asc): int {
            $left  = (string)($asc ? $a['created_at'] : $b['created_at']);
            $right = (string)($asc ? $b['created_at'] : $a['created_at']);
            return strcmp($left, $right);
        });
        return array_slice($rows, 0, 200);
    }

    /**
     * The decided-rows window. Bounded to the last 30 days by default so these
     * five queries stay cheap; an explicit لە بەروار drops it, because
     * otherwise asking for anything older than 30 days silently returns
     * nothing. date_to alone keeps the window (filterSql already caps the top
     * end), so there is never a lower-bound-free scan.
     */
    private function decidedWindowSql(array $filters, string $dateExpr): string
    {
        return !empty($filters['date_from'])
            ? ''
            : " AND {$dateExpr} >= NOW() - INTERVAL 30 DAY";
    }

    private function historyInvoices(array $filters, bool $approvedOnly = false): array
    {
        [$filterSql, $params] = $this->filterSql(
            $filters, 'ci.invoice_no', 'ci.doctor_id', 'COALESCE(ci.approved_at, ci.returned_at)'
        );
        $statusSql = $approvedOnly ? "ci.accounting_status = 'approved'" : "ci.accounting_status IN ('approved','returned')";
        if (($filters['approval'] ?? '') === 'auto') {
            $statusSql .= ' AND ci.approved_auto = 1';
        } elseif (($filters['approval'] ?? '') === 'manual') {
            $statusSql .= ' AND ci.approved_auto = 0';
        }
        $windowSql = $this->decidedWindowSql($filters, 'COALESCE(ci.approved_at, ci.returned_at)');
        $dir = self::dir($filters);
        $rows = $this->db->fetchAll(
            "SELECT ci.id, ci.case_id, ci.invoice_no, ci.total_amount, ci.discount_amount, ci.payment_intent,
                    ci.promised_date, ci.promised_amount, ci.accounting_status, ci.approved_auto,
                    COALESCE(ci.approved_at, ci.returned_at) AS ts,
                    p.id AS patient_id, p.full_name AS patient_name, p.phone AS patient_phone,
                    COALESCE(doc.full_name, sender.full_name) AS doctor_name,
                    approver.full_name AS approved_by_name,
                    CASE WHEN ci.created_by IS NOT NULL AND ci.created_by <> COALESCE(ci.doctor_id, 0)
                         THEN maker.full_name END AS made_by_name
             FROM case_invoices ci
             JOIN cases c ON c.id = ci.case_id
             JOIN patient_folders pf ON pf.id = c.folder_id
             JOIN patients p ON p.id = pf.patient_id
             LEFT JOIN users doc ON doc.id = ci.doctor_id
             LEFT JOIN users sender ON sender.id = ci.sent_by
             LEFT JOIN users approver ON approver.id = ci.approved_by
             LEFT JOIN users maker ON maker.id = ci.created_by
             WHERE {$statusSql}{$windowSql}{$filterSql}
             ORDER BY ts {$dir}, ci.id {$dir}",
            $params
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'kind' => 'invoice', 'id' => (int)$r['id'], 'uid' => 'invoice:' . $r['id'],
                'case_id' => (int)$r['case_id'],
                'patient_id' => (int)$r['patient_id'], 'patient_name' => $r['patient_name'],
                'doctor_name' => $r['doctor_name'], 'number' => $r['invoice_no'],
                'amount' => max(0, (float)$r['total_amount'] - (float)$r['discount_amount']),
                'payment_type' => null, 'intent' => $r['payment_intent'],
                'promised_date' => $r['promised_date'],
                'promised_amount' => $r['promised_amount'] !== null ? (float)$r['promised_amount'] : null,
                'created_at' => $r['ts'], 'status' => $r['accounting_status'], 'action' => null,
                // 🤖 خۆکار (the nightly run, AutoApproval) vs ✋ دەستی.
                'approved_auto' => $r['accounting_status'] === 'approved' ? (int)$r['approved_auto'] : null,
                'approved_by_name' => $r['approved_by_name'],
                'made_by_name' => $r['made_by_name'],
            ];
        }
        return $out;
    }

    /**
     * Decided arch work receipts. `amount` is the money actually collected on
     * the receipt — an arch visit bills nothing, so a receipt approved without
     * a payment legitimately shows zero.
     */
    private function historyArchWork(array $filters, bool $approvedOnly = false): array
    {
        if (!$this->db->tableExists('arch_work_receipts')) {
            return [];
        }
        [$filterSql, $params] = $this->filterSql(
            $filters, 'r.receipt_no', 'pf.doctor_id', 'r.approved_at'
        );
        $statusSql = $approvedOnly ? "r.status = 'approved'" : "r.status IN ('approved','returned')";
        $windowSql = $this->decidedWindowSql($filters, 'r.approved_at');
        $dir = self::dir($filters);
        $rows = $this->db->fetchAll(
            "SELECT r.id, r.case_id, r.receipt_no, r.status, r.paid_amount, r.approved_at AS ts,
                    p.id AS patient_id, p.full_name AS patient_name,
                    COALESCE(sender.full_name, doc.full_name) AS doctor_name
             FROM arch_work_receipts r
             JOIN cases c ON c.id = r.case_id
             JOIN patient_folders pf ON pf.id = c.folder_id
             JOIN patients p ON p.id = pf.patient_id
             LEFT JOIN users sender ON sender.id = r.sent_by
             LEFT JOIN users doc ON doc.id = pf.doctor_id
             WHERE {$statusSql}
               AND r.approved_at IS NOT NULL{$windowSql}{$filterSql}
             ORDER BY ts {$dir}, r.id {$dir}",
            $params
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'kind' => 'archwork', 'id' => (int)$r['id'], 'uid' => 'archwork:' . $r['id'],
                'case_id' => (int)$r['case_id'],
                'patient_id' => (int)$r['patient_id'], 'patient_name' => $r['patient_name'],
                'doctor_name' => $r['doctor_name'], 'number' => $r['receipt_no'],
                'amount' => (float)$r['paid_amount'],
                'payment_type' => null, 'intent' => null,
                'promised_date' => null, 'promised_amount' => null,
                'created_at' => $r['ts'], 'status' => $r['status'], 'action' => null,
            ];
        }
        return $out;
    }

    /**
     * payments has no generic updated_at column (schema.sql:201-217 plus
     * every migration touching payments — checked, none add one), but
     * refund() (classes/Payment.php:598-619) DOES stamp a real decision-time
     * column — reversed_at — when it flips status to 'refunded'
     * (2026_08_25_invoice_accounting_ledger.sql:50-54 adds the column). Use
     * it there via COALESCE so a payment refunded this week but created over
     * 30 days ago still surfaces. approve()/reject() still stamp nothing, so
     * approved/rejected rows keep falling back to payment_date (creation
     * time, not decision time) — an honest, still-open gap for those two
     * paths, not fixed by this COALESCE.
     */
    private function historyPayments(array $filters, bool $approvedOnly = false): array
    {
        [$filterSql, $params] = $this->filterSql(
            $filters, 'pay.payment_number', 'pay.received_by', 'COALESCE(pay.reversed_at, pay.payment_date)'
        );
        $statusSql = $approvedOnly ? "pay.status = 'approved'" : "pay.status IN ('approved','rejected','refunded')";
        $windowSql = $this->decidedWindowSql($filters, 'COALESCE(pay.reversed_at, pay.payment_date)');
        $dir = self::dir($filters);
        $rows = $this->db->fetchAll(
            "SELECT pay.id, pay.case_id, pay.payment_number, pay.amount, pay.payment_type, pay.status,
                    COALESCE(pay.reversed_at, pay.payment_date) AS ts,
                    p.id AS patient_id, p.full_name AS patient_name, p.phone AS patient_phone,
                    doc.full_name AS doctor_name
             FROM payments pay
             JOIN cases c ON c.id = pay.case_id
             JOIN patient_folders pf ON pf.id = c.folder_id
             JOIN patients p ON p.id = pf.patient_id
             LEFT JOIN users doc ON doc.id = pay.received_by
             WHERE {$statusSql}{$windowSql}{$filterSql}
             ORDER BY ts {$dir}, pay.id {$dir}",
            $params
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'kind' => 'payment', 'id' => (int)$r['id'], 'uid' => 'payment:' . $r['id'],
                'case_id' => (int)$r['case_id'],
                'patient_id' => (int)$r['patient_id'], 'patient_name' => $r['patient_name'],
                'doctor_name' => $r['doctor_name'], 'number' => $r['payment_number'],
                'amount' => (float)$r['amount'], 'payment_type' => $r['payment_type'],
                'intent' => null, 'promised_date' => null, 'promised_amount' => null,
                'created_at' => $r['ts'], 'status' => $r['status'], 'action' => null,
            ];
        }
        return $out;
    }

    /**
     * installment_plans also has no updated_at column (schema.sql:222-237,
     * 2026_08_26_installment_pending_status.sql — checked). Rejected plans
     * never get approved_at, so this falls back to created_at for them —
     * an approximation the brief's own fallback pattern (payments) already
     * accepts for the same structural reason.
     */
    private function historyInstallments(array $filters, bool $approvedOnly = false): array
    {
        [$filterSql, $params] = $this->filterSql(
            $filters, "CONCAT('P-', ip.id)", 'ip.created_by', 'COALESCE(ip.approved_at, ip.created_at)'
        );
        // A plan the accountant granted is 'active', and stays granted once it
        // finishes paying out ('completed') — both belong in پەسەندکراو.
        $statusSql = $approvedOnly ? "ip.status IN ('active','completed')" : "ip.status IN ('active','rejected')";
        $windowSql = $this->decidedWindowSql($filters, 'COALESCE(ip.approved_at, ip.created_at)');
        $dir = self::dir($filters);
        $rows = $this->db->fetchAll(
            "SELECT ip.id, ip.case_id, ip.total_amount, ip.status,
                    COALESCE(ip.approved_at, ip.created_at) AS ts,
                    p.id AS patient_id, p.full_name AS patient_name, p.phone AS patient_phone,
                    doc.full_name AS doctor_name
             FROM installment_plans ip
             JOIN cases c ON c.id = ip.case_id
             JOIN patient_folders pf ON pf.id = c.folder_id
             JOIN patients p ON p.id = pf.patient_id
             LEFT JOIN users doc ON doc.id = ip.created_by
             WHERE {$statusSql}{$windowSql}{$filterSql}
             ORDER BY ts {$dir}, ip.id {$dir}",
            $params
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'kind' => 'installment', 'id' => (int)$r['id'], 'uid' => 'installment:' . $r['id'],
                'case_id' => (int)$r['case_id'],
                'patient_id' => (int)$r['patient_id'], 'patient_name' => $r['patient_name'],
                'doctor_name' => $r['doctor_name'], 'number' => 'P-' . $r['id'],
                'amount' => (float)$r['total_amount'], 'payment_type' => null,
                'intent' => null, 'promised_date' => null, 'promised_amount' => null,
                'created_at' => $r['ts'], 'status' => $r['status'], 'action' => null,
            ];
        }
        return $out;
    }

    private function historyXray(array $filters, bool $approvedOnly = false): array
    {
        [$filterSql, $params] = $this->filterSql(
            $filters, "CONCAT('X-', xr.id)", 'xr.requested_by', 'xr.accountant_approved_at'
        );
        // accountant_approved_at is only ever stamped by the accountant's own
        // decision, so "left the two pending states AND has that stamp" IS the
        // approved set — minus the requests cancelled afterwards.
        $statusSql = $approvedOnly
            ? "xr.request_status NOT IN ('pending_accountant','returned_to_accountant','cancelled')"
            : "xr.request_status NOT IN ('pending_accountant','returned_to_accountant')";
        $windowSql = $this->decidedWindowSql($filters, 'xr.accountant_approved_at');
        $dir = self::dir($filters);
        $rows = $this->db->fetchAll(
            "SELECT xr.id, xr.amount, xr.final_amount, xr.payment_type, xr.request_status,
                    xr.accountant_approved_at AS ts,
                    p.id AS patient_id, p.full_name AS patient_name, p.phone AS patient_phone,
                    COALESCE(xed.name, u.full_name) AS doctor_name,
                    xed.name AS external_doctor_name
             FROM xray_requests xr
             JOIN patients p ON p.id = xr.patient_id
             LEFT JOIN users u ON u.id = xr.requested_by
             LEFT JOIN xray_external_doctors xed ON xed.id = xr.external_doctor_id
             WHERE {$statusSql}
               AND xr.accountant_approved_at IS NOT NULL{$windowSql}{$filterSql}
             ORDER BY ts {$dir}, xr.id {$dir}",
            $params
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'kind' => 'xray', 'id' => (int)$r['id'], 'uid' => 'xray:' . $r['id'],
                'patient_id' => (int)$r['patient_id'], 'patient_name' => $r['patient_name'],
                'doctor_name' => $r['doctor_name'], 'number' => 'X-' . $r['id'],
                'external_doctor_name' => $r['external_doctor_name'],
                'amount' => (float)($r['final_amount'] ?: $r['amount']),
                'payment_type' => $r['payment_type'], 'intent' => null,
                'promised_date' => null, 'promised_amount' => null,
                'created_at' => $r['ts'], 'status' => $r['request_status'], 'action' => null,
            ];
        }
        return $out;
    }

    // ================================================================
    // newSince
    // ================================================================

    /**
     * @return array ['count'=>int,'uids'=>string[]] across the four
     *   approval queues + station, for the polling badge/sound.
     */
    public function newSince(string $isoDatetime): array
    {
        $uids = [];

        foreach ($this->db->fetchAll(
            "SELECT id FROM case_invoices
             WHERE accounting_status = 'sent' AND COALESCE(sent_at, created_at) > ?",
            [$isoDatetime]
        ) as $r) {
            $uids[] = 'invoice:' . (int)$r['id'];
        }

        if ($this->db->tableExists('arch_work_receipts')) {
            foreach ($this->db->fetchAll(
                "SELECT id FROM arch_work_receipts
                 WHERE status = 'sent' AND COALESCE(sent_at, created_at) > ?",
                [$isoDatetime]
            ) as $r) {
                $uids[] = 'archwork:' . (int)$r['id'];
            }
        }

        foreach ($this->db->fetchAll(
            "SELECT id FROM payments WHERE status = 'pending' AND payment_date > ?",
            [$isoDatetime]
        ) as $r) {
            $uids[] = 'payment:' . (int)$r['id'];
        }

        foreach ($this->db->fetchAll(
            "SELECT id FROM installment_plans WHERE status = 'pending' AND created_at > ?",
            [$isoDatetime]
        ) as $r) {
            $uids[] = 'installment:' . (int)$r['id'];
        }

        foreach ($this->db->fetchAll(
            "SELECT id FROM xray_requests
             WHERE request_status IN ('pending_accountant','returned_to_accountant') AND created_at > ?",
            [$isoDatetime]
        ) as $r) {
            $uids[] = 'xray:' . (int)$r['id'];
        }

        foreach ($this->db->fetchAll(
            "SELECT wr.id
             FROM waiting_room wr
             LEFT JOIN visit_flow_log vfl ON vfl.id = (
                 SELECT MAX(v2.id) FROM visit_flow_log v2
                 WHERE v2.waiting_room_id = wr.id AND v2.to_status = 'at_accounting'
             )
             WHERE wr.status = 'at_accounting' AND wr.check_in_time >= CURDATE()
               AND COALESCE(vfl.created_at, wr.check_in_time) > ?
               {$this->stationDedupeSql()}",
            [$isoDatetime]
        ) as $r) {
            $uids[] = 'station:' . (int)$r['id'];
        }

        return ['count' => count($uids), 'uids' => $uids];
    }

    // ================================================================
    // Detail
    // ================================================================

    /** Full drawer payload for one queue row. Always includes patient_id. */
    public function detail(string $kind, int $id): array
    {
        switch ($kind) {
            case 'invoice':     return $this->detailInvoice($id);
            case 'archwork':    return $this->detailArchWork($id);
            case 'payment':     return $this->detailPayment($id);
            case 'advance':     return $this->detailAdvance($id);
            case 'installment': return $this->detailInstallment($id);
            case 'xray':        return $this->detailXray($id);
            case 'station':     return $this->detailStation($id);
            default:            return ['success' => false, 'message' => 'Unknown kind'];
        }
    }

    /** Patient behind a case, via the standard case -> folder -> patient path. */
    private function patientForCase(int $caseId)
    {
        return $this->db->fetch(
            "SELECT p.id, p.full_name, p.phone, p.patient_code
             FROM cases c
             JOIN patient_folders pf ON pf.id = c.folder_id
             JOIN patients p ON p.id = pf.patient_id
             WHERE c.id = ?",
            [$caseId]
        );
    }

    /**
     * In-place edits of one approved record — each «تەعدیل» save is logged
     * with its own action (invoice_approved_edited, payment_approved_edited,
     * installment_plan_approved_edited, xray_approved_edited): how many, and
     * the latest one's time and author. Rides on the (table_name, record_id)
     * index of activity_log.
     *
     * @return array{count:int, last_at:?string, last_by:?string}
     */
    private function approvedEditTrail(string $table, int $recordId, string $action): array
    {
        $row = $this->db->fetch(
            "SELECT COUNT(*) AS c, MAX(al.id) AS last_id
             FROM activity_log al
             WHERE al.table_name = ? AND al.record_id = ? AND al.action = ?",
            [$table, $recordId, $action]
        );
        $count = (int)($row['c'] ?? 0);
        if ($count === 0) {
            return ['count' => 0, 'last_at' => null, 'last_by' => null];
        }
        $last = $this->db->fetch(
            "SELECT al.created_at, u.full_name
             FROM activity_log al LEFT JOIN users u ON u.id = al.user_id
             WHERE al.id = ?",
            [(int)$row['last_id']]
        );
        return [
            'count'   => $count,
            'last_at' => $last['created_at'] ?? null,
            'last_by' => $last['full_name'] ?? null,
        ];
    }

    /**
     * The patient's ئامانەت receipts that still hold money. The invoice and
     * payment windows list them so the accountant can match a figure to the
     * counter receipt it came from before booking it as new cash — the same
     * money booked twice (once at the counter, once as وەرگیراو) is exactly
     * the mistake this guards against.
     */
    private function openAdvanceReceipts(int $patientId): array
    {
        if ($patientId <= 0) {
            return [];
        }
        $open = [];
        foreach ((new PatientAdvance())->listFor($patientId, 50) as $r) {
            if ((float)$r['available_amount'] > 0.009) {
                $open[] = [
                    'advance_no'       => $r['advance_no'],
                    'received_at'      => $r['received_at'],
                    'amount'           => (float)$r['amount'],
                    'available'        => (float)$r['available_amount'],
                    'received_by_name' => $r['received_by_name'] ?? null,
                ];
            }
        }
        return $open;
    }

    private function detailInvoice(int $id): array
    {
        $ledger = new AccountingLedger();
        $invoice = $ledger->getInvoice($id);
        if (!$invoice) {
            return ['success' => false, 'message' => 'وەسڵ نەدۆزرایەوە'];
        }
        $caseId = (int)$invoice['case_id'];
        $patient = $this->patientForCase($caseId);
        // tooth_surface (2026_07_11_add_tooth_surface.sql) and total_price
        // (the pre-discount quantity*unit_price figure, schema.sql:182) are
        // added on top of the columns the drawer already used, so the wide
        // modal's table can render a teeth cell and match the reference
        // pane's column set 1:1. final_price stays too — it, not the raw
        // total_price, is what invoice_pane.php and cases/receipt.php both
        // print as نرخی کۆ (it nets out any per-row `discount`), so the
        // drawer keeps reading it for that cell to stay visually identical
        // to the reference; total_price rides along for completeness.
        $treatments = $this->db->fetchAll(
            "SELECT ct.id, ct.treatment_id, ct.treatment_name, ct.tooth_number,
                    ct.tooth_surface, ct.arch_type, ct.quantity, ct.unit_price,
                    ct.total_price, ct.final_price,
                    COALESCE(t.requires_tooth_number, 1) AS requires_tooth_number
             FROM case_treatments ct
             LEFT JOIN treatments t ON t.id = ct.treatment_id
             WHERE invoice_id = ? AND status <> 'cancelled'
             ORDER BY ct.created_at, ct.id",
            [$id]
        );
        return [
            'success' => true,
            'kind' => 'invoice',
            'advance_available' => (new PatientAdvance())->balanceOf((int)($patient['id'] ?? 0)),
            'advance_receipts' => $this->openAdvanceReceipts((int)($patient['id'] ?? 0)),
            'invoice' => $invoice,
            'treatments' => $treatments,
            'summary' => $ledger->getSummary($caseId),
            'patient' => $patient,
            'patient_id' => $patient['id'] ?? null,
            'intent' => [
                'payment_intent'  => $invoice['payment_intent'] ?? null,
                'promised_date'   => $invoice['promised_date'] ?? null,
                'promised_amount' => $invoice['promised_amount'] ?? null,
                // Settlement figures the accountant edits directly. Sent from
                // the server rather than recomputed in JS so the screen and
                // the stored rule can never disagree about what is owed.
                'received_amount' => (float)($invoice['draft_received_amount'] ?? 0),
                'prior_paid_amount' => (float)($invoice['prior_paid_amount'] ?? 0),
                'net_amount'      => max(0, (float)$invoice['total_amount'] - (float)$invoice['discount_amount']),
                'remaining'       => CaseInvoice::remainingOf($invoice),
                'editable'        => in_array($invoice['accounting_status'] ?? '', ['sent', 'returned'], true),
            ],
            // Whether the money boxes of an APPROVED invoice are live here: the
            // invoice stays approved and keeps its approval date, it is simply
            // corrected in place (CaseInvoice::editApproved enforces the same
            // switch on the write side; this only decides what is drawn).
            'can_edit_approved' => ($invoice['accounting_status'] ?? '') === 'approved'
                && Settings::allowEditApprovedInvoice(),
            // How often, when and by whom this approved invoice was edited in
            // place — so an edited invoice never looks untouched.
            'approved_edits' => $this->approvedEditTrail('case_invoices', $id, 'invoice_approved_edited'),
            // Private doctor -> accounting remark. Never printed on a receipt.
            'doctor_note' => $invoice['doctor_note_to_accounting'] ?? null,
        ];
    }

    /**
     * وەسڵی کارە ئەنجامدراوەکان. The works travel WITHOUT prices — an arch
     * visit adds no charge — and the money on the drawer is the patient's
     * whole outstanding debt, which is what the accountant collects against.
     */
    private function detailArchWork(int $id): array
    {
        require_once __DIR__ . '/ArchWorkReceipt.php';
        $service = new ArchWorkReceipt();
        $receipt = $service->getById($id);
        if (!$receipt) {
            return ['success' => false, 'message' => 'وەسڵ نەدۆزرایەوە'];
        }
        $caseId = (int)$receipt['case_id'];
        $patient = $this->patientForCase($caseId);
        $debt = $service->debtFor($receipt);
        $paid = (float)$receipt['paid_amount'];

        return [
            'success'     => true,
            'kind'        => 'archwork',
            'receipt'     => $receipt,
            'works'       => $service->getWorks($id),
            'patient'     => $patient,
            'patient_id'  => $patient['id'] ?? null,
            'money'       => [
                'debt'      => $debt,                       // قەرزی پێشوو
                'paid'      => $paid,                       // دراو
                'remaining' => max(0, $debt - $paid),       // ماوە
                'editable'  => $receipt['status'] === 'sent',
            ],
            'doctor_note' => $receipt['doctor_notes'] ?? null,
        ];
    }

    private function detailPayment(int $id): array
    {
        $payment = $this->db->fetch("SELECT * FROM payments WHERE id = ?", [$id]);
        if (!$payment) {
            return ['success' => false, 'message' => 'پارەدان نەدۆزرایەوە'];
        }
        $caseId = (int)$payment['case_id'];
        $ledger = new AccountingLedger();
        // getPayments() decorates with allocations/allocated_amount/received_by_name
        // etc.; pick the matching row out so the drawer gets the full picture.
        $full = $payment;
        foreach ($ledger->getPayments($caseId) as $p) {
            if ((int)$p['id'] === $id) {
                $full = $p;
                break;
            }
        }
        $patient = $this->patientForCase($caseId);
        // «تەعدیل» on an approved receipt — offered only when the switch is on
        // AND Payment::editApproved would accept this receipt; otherwise the
        // reason is shown instead of a button that could only fail.
        $isApproved = ($payment['status'] ?? '') === 'approved';
        $editBlock = $isApproved ? (new Payment())->approvedEditBlockReason($payment) : null;
        return [
            'success' => true,
            'kind' => 'payment',
            'advance_available' => (new PatientAdvance())->balanceOf((int)($patient['id'] ?? 0)),
            'advance_receipts' => $this->openAdvanceReceipts((int)($patient['id'] ?? 0)),
            'payment' => $full,
            'summary' => $ledger->getSummary($caseId),
            'patient' => $patient,
            'patient_id' => $patient['id'] ?? null,
            'can_edit_approved' => $isApproved && $editBlock === null && Settings::allowEditApprovedInvoice(),
            'edit_block_reason' => $editBlock,
            'approved_edits' => $isApproved
                ? $this->approvedEditTrail('payments', $id, 'payment_approved_edited')
                : ['count' => 0, 'last_at' => null, 'last_by' => null],
        ];
    }

    private function detailAdvance(int $id): array
    {
        require_once __DIR__ . '/PatientAdvance.php';
        $manager = new PatientAdvance();
        $advance = $manager->getById($id);
        if (!$advance) {
            return ['success' => false, 'message' => 'وەسڵی قەرزی کۆن نەدۆزرایەوە'];
        }
        $allocations = $manager->allocationsOf($id);
        $used = 0.0;
        foreach ($allocations as $allocation) {
            if (empty($allocation['reversed_at'])) {
                $used += (float)$allocation['amount'];
            }
        }
        $remaining = round(max(0, (float)$advance['amount'] - (float)$advance['refunded_amount'] - $used), 2);
        if ((float)$advance['refunded_amount'] > 0.009 && $remaining <= 0.009) {
            $status = 'refunded';
        } elseif ($remaining <= 0.009) {
            $status = 'allocated';
        } elseif ($used > 0.009) {
            $status = 'partial';
        } else {
            $status = 'open';
        }
        $advance['allocated_amount'] = round($used, 2);
        $advance['available_amount'] = $remaining;
        $advance['status'] = $status;
        $patientId = (int)$advance['patient_id'];
        return [
            'success' => true,
            'kind' => 'advance',
            'advance' => $advance,
            'allocations' => $allocations,
            'summary' => $manager->summaryFor($patientId),
            'patient_id' => $patientId,
            'patient' => [
                'id' => $patientId,
                'full_name' => $advance['patient_name'],
                'phone' => $advance['patient_phone'],
                'patient_code' => $advance['patient_code'],
            ],
        ];
    }

    private function detailInstallment(int $id): array
    {
        $installment = new Installment();
        $plan = $installment->getPlanById($id);
        if (!$plan) {
            return ['success' => false, 'message' => 'پلان نەدۆزرایەوە'];
        }
        $caseId = (int)$plan['case_id'];
        $eligibility = null;
        if (!empty($plan['invoice_id'])) {
            $caseInvoice = new CaseInvoice();
            $eligibility = $caseInvoice->installmentEligibility($caseId, (int)$plan['invoice_id']);
        }
        $patient = $this->patientForCase($caseId);
        return [
            'success' => true,
            'kind' => 'installment',
            'plan' => $plan,
            'summary' => $installment->getPlanSummary($id),
            'proposed_terms' => [
                'proposed_down_payment'  => $plan['proposed_down_payment'] ?? null,
                'number_of_installments' => $plan['number_of_installments'] ?? null,
                'start_date'             => $plan['start_date'] ?? null,
                'total_amount'           => $plan['total_amount'] ?? null,
            ],
            'invoice_remaining' => $eligibility,
            'patient' => $patient,
            'patient_id' => $patient['id'] ?? null,
            // «تەعدیل» on an approved (active/completed) plan, with the limits
            // Installment::editApprovedPlan enforces so the form can say them.
            'can_edit_approved' => in_array($plan['status'], ['active', 'completed'], true)
                && Settings::allowEditApprovedInvoice(),
            'edit_limits' => in_array($plan['status'], ['active', 'completed'], true)
                ? $installment->approvedEditLimits($id)
                : null,
            'approved_edits' => in_array($plan['status'], ['active', 'completed'], true)
                ? $this->approvedEditTrail('installment_plans', $id, 'installment_plan_approved_edited')
                : ['count' => 0, 'last_at' => null, 'last_by' => null],
        ];
    }

    private function detailXray(int $id): array
    {
        $xrayManager = new XrayManager();
        $request = $xrayManager->getById($id);
        if (!$request) {
            return ['success' => false, 'message' => 'داواکاری نەدۆزرایەوە'];
        }
        $history = $this->db->fetchAll(
            "SELECT h.*, u.full_name AS changed_by_name
             FROM xray_request_history h
             LEFT JOIN users u ON u.id = h.changed_by_user_id
             WHERE h.request_id = ?
             ORDER BY h.created_at, h.id",
            [$id]
        );
        // What was collected and what is still owed. Only a request settled
        // through the debt channel — fully, or part cash + part debt
        // (XrayManager::approvePartialAndSendToXray) — has a patient_xray_debts
        // row; a plain cash settlement has none and needs no breakdown.
        $settlement = null;
        if ($this->db->tableExists('patient_xray_debts')) {
            $debt = $this->db->fetch(
                "SELECT COALESCE(SUM(amount), 0) AS total, COALESCE(SUM(remaining_amount), 0) AS remaining
                 FROM patient_xray_debts WHERE request_id = ?",
                [$id]
            );
            if ($debt && (float)$debt['total'] > 0.009) {
                $settlement = [
                    'total'     => (float)$debt['total'],
                    'remaining' => (float)$debt['remaining'],
                    'paid'      => max(0.0, (float)$debt['total'] - (float)$debt['remaining']),
                ];
            }
        }

        // Approved = the same rule the پەسەندکراو tab lists by (historyXray):
        // stamped by the accountant, out of the two pending states, not
        // cancelled. Its «تەعدیل» form starts from approvedMoneyState() — the
        // same reading XrayManager::editApprovedSettlement edits from.
        $isApproved = !empty($request['accountant_approved_at'])
            && !in_array($request['request_status'] ?? '', ['pending_accountant', 'returned_to_accountant', 'cancelled'], true);
        $money = $isApproved ? $xrayManager->approvedMoneyState($id) : null;

        return [
            'success' => true,
            'kind' => 'xray',
            'request' => $request,
            'history' => $history,
            'settlement' => $settlement,
            'patient_id' => isset($request['patient_id']) ? (int)$request['patient_id'] : null,
            'is_approved' => $isApproved,
            'approved_money' => $money,
            'can_edit_approved' => $isApproved && !empty($money['editable']) && Settings::allowEditApprovedInvoice(),
            'edit_block_reason' => ($isApproved && $money && empty($money['editable']))
                ? 'ئەم داواکارییە بێ بەرامبەرە یان پارەکەی گەڕێندراوەتەوە — پارەکەی ناگۆڕدرێت'
                : null,
            'approved_edits' => $isApproved
                ? $this->approvedEditTrail('xray_requests', $id, 'xray_approved_edited')
                : ['count' => 0, 'last_at' => null, 'last_by' => null],
        ];
    }

    /** Copied from api/accountant-feed.php's station detail (action=detail, kind=station). */
    private function detailStation(int $id): array
    {
        $wr = $this->db->fetch(
            "SELECT wr.*, p.full_name AS patient_name, p.patient_code, p.phone AS patient_phone,
                    du.full_name AS doctor_name,
                    vfl.created_at AS moved_at, vfl.note AS flow_note, mu.full_name AS sender_name
             FROM waiting_room wr
             JOIN patients p ON wr.patient_id = p.id
             LEFT JOIN users du ON wr.doctor_id = du.id
             LEFT JOIN visit_flow_log vfl ON vfl.id = (
                 SELECT MAX(v2.id) FROM visit_flow_log v2
                 WHERE v2.waiting_room_id = wr.id AND v2.to_status = 'at_accounting'
             )
             LEFT JOIN users mu ON vfl.moved_by = mu.id
             WHERE wr.id = ?",
            [$id]
        );
        if (!$wr) {
            return ['success' => false, 'message' => 'Not found'];
        }
        // Review fix: was a gross mirror-column sum (cases.total_amount -
        // paid_amount - discount_amount, no x-ray, no credit netting) —
        // disagreed with the net figure the statement-page link right below
        // it shows. PatientFinance::summary()['net_debt'] is the same derived
        // ledger figure used everywhere else in this branch (case balance +
        // x-ray remaining - credit, floored at 0).
        $netDebt = (new PatientFinance())->summary((int)$wr['patient_id'])['net_debt'];
        return [
            'success' => true,
            'kind' => 'station',
            'station' => $wr,
            'debt' => (float)$netDebt,
            'patient_id' => (int)$wr['patient_id'],
        ];
    }
}
