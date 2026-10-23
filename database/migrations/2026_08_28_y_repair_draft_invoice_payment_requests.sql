-- Repair only the obsolete inline-entry requests that still point at an
-- editable draft invoice. Approved/sent historical invoices and every
-- approved payment are intentionally untouched.
UPDATE case_invoices ci
JOIN payment_allocations pa ON pa.invoice_id=ci.id
JOIN payments p ON p.id=pa.payment_id
SET ci.draft_received_amount=GREATEST(ci.draft_received_amount,pa.allocated_amount),
    ci.updated_at=NOW()
WHERE ci.accounting_status IN ('draft','returned')
  AND p.status='pending'
  AND p.idempotency_key LIKE 'ivp-%'
  AND (SELECT COUNT(*) FROM payment_allocations px WHERE px.payment_id=p.id)=1;

UPDATE payments p
JOIN payment_allocations pa ON pa.payment_id=p.id
JOIN case_invoices ci ON ci.id=pa.invoice_id
SET p.status='rejected',
    p.notes=TRIM(CONCAT(COALESCE(p.notes,''), '\nRepaired: inline amount restored to its draft treatment invoice.'))
WHERE ci.accounting_status IN ('draft','returned')
  AND p.status='pending'
  AND p.idempotency_key LIKE 'ivp-%'
  AND (SELECT COUNT(*) FROM payment_allocations px WHERE px.payment_id=p.id)=1;

DELETE pa
FROM payment_allocations pa
JOIN payments p ON p.id=pa.payment_id
JOIN case_invoices ci ON ci.id=pa.invoice_id
WHERE ci.accounting_status IN ('draft','returned')
  AND p.status='rejected'
  AND p.idempotency_key LIKE 'ivp-%'
  AND p.notes LIKE '%Repaired: inline amount restored to its draft treatment invoice.%';
