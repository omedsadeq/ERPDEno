-- Migration: historical_cases (کەیسی کۆن)
-- Date: 2026-09-14
-- Description: Work done before this system existed. A doctor opens the case
-- as "کۆن", enters the treatments (so the chart is complete) and the money the
-- patient ALREADY paid outside the system as دراوی پێشوو.
--
-- case_invoices.prior_paid_amount is deliberately a separate column from
-- draft_received_amount: draft_received_amount is chair-side cash and is
-- counted as collected money by accountant/financial-summary.php and the
-- doctor daily/profile reports. prior_paid_amount reduces what the patient
-- owes (balances, statement, debts) but is never read by any cash report.

ALTER TABLE cases ADD COLUMN is_historical TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE cases ADD COLUMN historical_date DATE NULL DEFAULT NULL;
ALTER TABLE case_invoices ADD COLUMN prior_paid_amount DECIMAL(15,2) NOT NULL DEFAULT 0;

-- Same view as 2026_08_28_z_invoice_bundle_financial_view.sql, with the
-- pre-system money counted as paid alongside the chair-side amount.
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
            GREATEST(0,COALESCE(ci.draft_received_amount,0))
              + GREATEST(0,COALESCE(ci.prior_paid_amount,0))
              + COALESCE(ap.approved_paid,0)
        ) ELSE 0 END),0) AS paid_amount,
    GREATEST(0,
        COALESCE(SUM(CASE
            WHEN ci.accounting_status='approved' THEN GREATEST(0,ci.total_amount-ci.discount_amount)
            ELSE 0 END),0)
        -
        COALESCE(SUM(CASE
            WHEN ci.accounting_status='approved' THEN LEAST(
                GREATEST(0,ci.total_amount-ci.discount_amount),
                GREATEST(0,COALESCE(ci.draft_received_amount,0))
                  + GREATEST(0,COALESCE(ci.prior_paid_amount,0))
                  + COALESCE(ap.approved_paid,0)
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
