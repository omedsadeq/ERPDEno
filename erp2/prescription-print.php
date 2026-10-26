<?php
/**
 * دەرمانخانە — a doctor's prescription, printed on the doctor's own A5
 * landscape sheet (includes/rx_sheet.php, laid out like «lezan a5 v3.pdf»).
 *
 * Printed by the doctor from the case (cases/view.php), by the pharmacy for a
 * patient who buys outside (erp2/prescriptions.php «دەرەوەی کلینیک»), or
 * after the sale with the prices and a «فرۆشرا» stamp. The barcode on it
 * brings the prescription back into the till's cart (erp2/pos.php).
 *
 *   ?id=         the prescription
 *   ?paper=      blank | pad   (else what this computer used last)
 *   ?sold=1      start with the prices and the stamp (sold prescriptions)
 *   ?embed=1     inside the print dialog (assets/js/rx-print.js): no bar
 *   ?autoprint=1 print as soon as it opens
 */

require_once __DIR__ . '/_bootstrap.php';
if (!erp2_can('erp2.prescriptions.view') && !erp2_can('erp2.prescriptions.create')) {
    erp2_require('erp2.prescriptions.view');
}
require_once __DIR__ . '/../classes/RxProfile.php';
require_once __DIR__ . '/../includes/rx_sheet.php';

$db = Database::getInstance();
$id = (int)($_GET['id'] ?? 0);
$pr = $db->fetch(
    "SELECT pr.*, pa.full_name AS patient_name, pa.date_of_birth,
            si.invoice_number, si.invoice_date, si.grand_total AS invoice_total
     FROM erp2_prescriptions pr
     JOIN patients pa ON pa.id = pr.patient_id
     LEFT JOIN erp2_sales_invoices si ON si.id = pr.invoice_id AND si.status = 'confirmed'
     WHERE pr.id = :id",
    ['id' => $id]
);
if (!$pr) {
    http_response_code(404);
    exit('ڕەچەتە نەدۆزرایەوە');
}
$items = $db->fetchAll(
    "SELECT i.product_id, i.product_name, i.quantity, i.dosage, i.notes,
            t.name AS type_name, un.name AS unit_name
     FROM erp2_prescription_items i
     LEFT JOIN erp2_products p ON p.id = i.product_id
     LEFT JOIN erp2_product_types t ON t.id = p.type_id
     LEFT JOIN erp2_units un ON un.id = p.unit_id
     WHERE i.prescription_id = :id
     ORDER BY i.id",
    ['id' => $id]
);

// Sold: what each medicine came to on the invoice that sold it.
$sold = null;
$paid = [];
if ($pr['status'] === 'sold' && !empty($pr['invoice_number'])) {
    foreach ($db->fetchAll(
        "SELECT product_id, SUM(line_total) AS total FROM erp2_sales_items WHERE invoice_id = :inv GROUP BY product_id",
        ['inv' => (int)$pr['invoice_id']]
    ) as $r) {
        $paid[(int)$r['product_id']] = (float)$r['total'];
    }
    $sold = [
        'invoice' => $pr['invoice_number'],
        'date'    => $pr['invoice_date'],
        'total'   => number_format((float)$pr['invoice_total'], 0),
    ];
}

$age = '';
if (!empty($pr['date_of_birth']) && $pr['date_of_birth'] !== '0000-00-00') {
    try { $age = (string)(new DateTime($pr['date_of_birth']))->diff(new DateTime())->y; } catch (Throwable $ex) { $age = ''; }
}
$qty = fn($v) => rtrim(rtrim(number_format((float)$v, 3, '.', ''), '0'), '.');

$doctor = $pr['doctor_id'] ? RxProfile::forUser((int)$pr['doctor_id']) : RxProfile::resolve([], []);

rx_sheet_page([
    'title'   => 'ڕەچەتە #' . $id . ' — ' . $pr['patient_name'],
    'doctor'  => $doctor,
    'patient' => ['name' => $pr['patient_name'], 'age' => $age],
    'date'    => substr((string)$pr['created_at'], 0, 10),
    'items'   => array_map(fn($it) => [
        'name'   => $it['product_name'],
        'form'   => trim((string)($it['notes'] ?: $it['type_name'])),   // the circled form, else the item's own
        'qty'    => $qty($it['quantity']),
        'unit'   => (string)($it['unit_name'] ?? ''),
        'dosage' => (string)$it['dosage'],
        'total'  => isset($paid[(int)$it['product_id']]) ? number_format($paid[(int)$it['product_id']], 0) : '',
    ], $items),
    'notes'     => (string)($pr['notes'] ?? ''),
    'rx_id'     => $id,
    'barcode'   => RxProfile::barcode($id),
    'sold'      => $sold,
    'embed'     => !empty($_GET['embed']),
    'bar'       => empty($_GET['embed']),
    'autoprint' => !empty($_GET['autoprint']),
]);
