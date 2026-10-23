-- Older inline requests could carry the intended draft in payments.invoice_id
-- while FIFO incorrectly allocated them to an older approved invoice. Restore
-- the amount to that origin draft and retire only the still-pending request.
UPDATE case_invoices ci
JOIN payments p ON p.invoice_id=ci.id
SET ci.draft_received_amount=GREATEST(ci.draft_received_amount,p.amount),
    ci.updated_at=NOW()
WHERE ci.accounting_status IN ('draft','returned')
  AND p.status='pending'
  AND p.idempotency_key LIKE 'ivp-%';

UPDATE payments p
JOIN case_invoices ci ON ci.id=p.invoice_id
SET p.status='rejected',
    p.notes=TRIM(CONCAT(COALESCE(p.notes,''), '\nRepaired v2: inline amount restored to its origin draft treatment invoice.'))
WHERE ci.accounting_status IN ('draft','returned')
  AND p.status='pending'
  AND p.idempotency_key LIKE 'ivp-%';

DELETE pa
FROM payment_allocations pa
JOIN payments p ON p.id=pa.payment_id
WHERE p.status='rejected'
  AND p.idempotency_key LIKE 'ivp-%'
  AND p.notes LIKE '%Repaired v2: inline amount restored to its origin draft treatment invoice.%';

