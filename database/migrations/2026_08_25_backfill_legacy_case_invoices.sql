-- Preserve cases created before visit invoices existed by giving each one a
-- single approved legacy invoice. This converts historical totals/payments to
-- the same source of truth without changing their amounts or dates.

INSERT INTO case_invoices (
    case_id, doctor_id, invoice_seq, invoice_no, work_date,
    total_amount, discount_amount, discount_mode, discount_value,
    status, accounting_status, notes, accounting_notes,
    sent_by, sent_at, approved_at, created_at, updated_at
)
SELECT
    c.id,
    pf.doctor_id,
    1,
    CONCAT(c.case_number, '-1'),
    COALESCE(DATE(MIN(ct.performed_date)), DATE(c.created_at)),
    c.total_amount,
    LEAST(c.total_amount, GREATEST(0, c.discount_amount)),
    CASE WHEN c.discount_type = 'percentage' THEN 'percent' ELSE 'amount' END,
    GREATEST(0, c.discount_value),
    CASE
        WHEN c.paid_amount + c.discount_amount >= c.total_amount - 0.009 THEN 'processed'
        ELSE 'sent'
    END,
    'approved',
    c.notes,
    'Migrated from the pre-invoice case ledger',
    COALESCE(c.created_by, pf.doctor_id),
    c.created_at,
    c.created_at,
    c.created_at,
    c.updated_at
FROM cases c
JOIN patient_folders pf ON pf.id = c.folder_id
LEFT JOIN case_invoices existing_invoice ON existing_invoice.case_id = c.id
LEFT JOIN case_treatments ct ON ct.case_id = c.id AND ct.status <> 'cancelled'
WHERE existing_invoice.id IS NULL
  AND c.total_amount > 0
GROUP BY c.id, pf.doctor_id;

UPDATE case_treatments ct
JOIN case_invoices ci
  ON ci.case_id = ct.case_id
 AND ci.accounting_notes = 'Migrated from the pre-invoice case ledger'
SET ct.invoice_id = ci.id
WHERE ct.invoice_id IS NULL;

INSERT IGNORE INTO payment_allocations (
    payment_id, invoice_id, allocated_amount, created_by, created_at
)
SELECT
    p.id,
    ci.id,
    p.amount,
    COALESCE(p.approved_by, p.received_by),
    COALESCE(p.created_at, p.payment_date, CURRENT_TIMESTAMP)
FROM payments p
JOIN case_invoices ci
  ON ci.case_id = p.case_id
 AND ci.accounting_notes = 'Migrated from the pre-invoice case ledger'
WHERE p.status = 'approved'
  AND p.amount > 0;

UPDATE payments p
JOIN case_invoices ci
  ON ci.case_id = p.case_id
 AND ci.accounting_notes = 'Migrated from the pre-invoice case ledger'
SET p.invoice_id = ci.id
WHERE p.invoice_id IS NULL;

UPDATE installment_plans ip
JOIN case_invoices ci
  ON ci.case_id = ip.case_id
 AND ci.accounting_notes = 'Migrated from the pre-invoice case ledger'
SET ip.invoice_id = ci.id
WHERE ip.invoice_id IS NULL;
