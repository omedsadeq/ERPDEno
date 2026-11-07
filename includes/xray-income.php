<?php
/**
 * X-ray income: XrayManager::getIncome() payment by payment — a request paid
 * in cash, on its paid date, and every later payment on an x-ray debt, on its
 * own day. The income sheet's X-ray row is these lines' total, and the detail
 * dialog (includes/income-detail.php) shows them type by type.
 */

const XRAY_INCOME_TYPES = [
    'panoramic'     => ['label' => 'OPG',  'color' => '#0284c7'],
    'cbct'          => ['label' => 'CBCT', 'color' => '#7c3aed'],
    'cephalometric' => ['label' => 'Ceph', 'color' => '#d97706'],
    'periapical'    => ['label' => 'P.A',  'color' => '#0f766e'],
];

/** Every x-ray payment of a period, newest first: kind 'cash' (a paid request) or 'debt' (a payment on its debt). */
function xray_income_lines(Database $db, string $from, string $to): array
{
    $hasDebts = $db->tableExists('patient_xray_debts');
    $lines = $db->fetchAll(
        "SELECT xr.id, xr.request_number, xr.xray_type, xr.final_amount AS amount,
                DATE(COALESCE(xr.paid_at, xr.created_at)) AS day, p.id AS patient_id, p.full_name AS patient, 'cash' AS kind
         FROM xray_requests xr
         LEFT JOIN patients p ON p.id = xr.patient_id
         WHERE xr.payment_status = 'paid'
           " . ($hasDebts ? "AND NOT EXISTS (SELECT 1 FROM patient_xray_debts pxd WHERE pxd.request_id = xr.id)" : '') . "
           AND DATE(COALESCE(xr.paid_at, xr.created_at)) BETWEEN :f AND :t",
        ['f' => $from, 't' => $to]
    );
    if ($hasDebts && $db->tableExists('xray_debt_payments')) {
        $lines = array_merge($lines, $db->fetchAll(
            "SELECT xr.id, xr.request_number, xr.xray_type, xdp.amount,
                    DATE(xdp.created_at) AS day, p.id AS patient_id, p.full_name AS patient, 'debt' AS kind
             FROM xray_debt_payments xdp
             JOIN patient_xray_debts pxd ON pxd.id = xdp.debt_id
             JOIN xray_requests xr ON xr.id = pxd.request_id
             LEFT JOIN patients p ON p.id = xr.patient_id
             WHERE DATE(xdp.created_at) BETWEEN :f AND :t",
            ['f' => $from, 't' => $to]
        ));
    }
    usort($lines, fn($a, $b) => [$b['day'], (int)$b['id']] <=> [$a['day'], (int)$a['id']]);
    return $lines;
}

/**
 * Per type: amount, and count as the income sheet counts — a request once,
 * however many payments on its debt fell in the period.
 */
function xray_income_totals(array $lines): array
{
    $totals = [];
    foreach (array_keys(XRAY_INCOME_TYPES) as $type) {
        $totals[$type] = ['count' => 0, 'amount' => 0.0];
    }
    $seen = [];
    foreach ($lines as $line) {
        $type = $line['xray_type'];
        $totals[$type] ??= ['count' => 0, 'amount' => 0.0];
        $totals[$type]['amount'] += (float)$line['amount'];
        $key = $line['kind'] . $line['id'];
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $totals[$type]['count']++;
        }
    }
    return $totals;
}
