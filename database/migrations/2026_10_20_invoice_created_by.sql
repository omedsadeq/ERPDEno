-- Who really opened an invoice. doctor_id is whose work and money it is (the
-- cashbox and the doctor's income go by it); created_by is the person who
-- made it — an admin or accountant filing a receipt for a doctor's patient —
-- so the case page and the Approval Center can say «لەلایەن … دروستکراوە».
ALTER TABLE case_invoices
    ADD COLUMN IF NOT EXISTS created_by INT NULL AFTER doctor_id;

-- Existing invoices: the user on their invoice_created audit entry.
UPDATE case_invoices ci
JOIN (
    SELECT record_id, MIN(id) AS first_id
    FROM activity_log
    WHERE action = 'invoice_created' AND table_name = 'case_invoices'
    GROUP BY record_id
) f ON f.record_id = ci.id
JOIN activity_log al ON al.id = f.first_id
SET ci.created_by = al.user_id
WHERE ci.created_by IS NULL AND al.user_id IS NOT NULL;
