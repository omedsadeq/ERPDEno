CREATE OR REPLACE VIEW v_case_financial_summary AS
SELECT
    c.id AS case_id,
    COALESCE(SUM(CASE
        WHEN ci.accounting_status NOT IN ('draft','cancelled') THEN ci.total_amount
        ELSE 0 END),0) AS total_invoiced,
    COALESCE(SUM(CASE
        WHEN ci.accounting_status NOT IN ('draft','cancelled') THEN ci.discount_amount
        ELSE 0 END),0) AS total_discount,
    COALESCE(SUM(CASE
        WHEN ci.accounting_status='approved' THEN GREATEST(0,ci.total_amount-ci.discount_amount)
        ELSE 0 END),0) AS approved_amount,
    COALESCE(SUM(CASE
        WHEN ci.accounting_status='approved' THEN LEAST(
            GREATEST(0,ci.total_amount-ci.discount_amount),
            COALESCE(ap.approved_paid,0)
        ) ELSE 0 END),0) AS paid_amount,
    GREATEST(0,
        COALESCE(SUM(CASE
            WHEN ci.accounting_status='approved' THEN GREATEST(0,ci.total_amount-ci.discount_amount)
            ELSE 0 END),0)
        -
        COALESCE(SUM(CASE
            WHEN ci.accounting_status='approved' THEN LEAST(
                GREATEST(0,ci.total_amount-ci.discount_amount),
                COALESCE(ap.approved_paid,0)
            ) ELSE 0 END),0)
    ) AS balance
FROM cases c
LEFT JOIN case_invoices ci ON ci.case_id=c.id
LEFT JOIN (
    SELECT pa.invoice_id,SUM(pa.allocated_amount) AS approved_paid
    FROM payment_allocations pa
    JOIN payments p ON p.id=pa.payment_id AND p.status='approved'
    GROUP BY pa.invoice_id
) ap ON ap.invoice_id=ci.id
GROUP BY c.id;

