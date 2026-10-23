-- Migration: patient_advances (ئامانەتی نەخۆش / وەسڵی پێشوەختە)
-- Date: 2026-09-22
-- Description: Money taken at the counter BEFORE any case exists. The clinic
-- opens at 08:00 but the doctors arrive at 12:00, so a patient can turn up in
-- the morning to settle an old balance while the doctor has not yet opened the
-- (historical) case for them. Reception takes the cash, prints a numbered
-- receipt, and the money sits on the patient — not on a case. When the doctor
-- arrives and opens the case, they allocate it onto that case's invoice.
--
-- TWO SEPARATE EVENTS, deliberately stored in two different places:
--
--   1. patient_advances     — the counter receipt. REAL cash: it belongs to the
--                             day and the staff member who took it, and every
--                             cash report must ADD it as money in.
--
--   2. case_invoices.advance_paid_amount — the allocation. NO cash moves: the
--                             money was already counted on the day of (1).
--
-- advance_paid_amount is modelled on prior_paid_amount (2026_09_14_historical_
-- cases.sql) for exactly the same reason, and the reason is worth repeating:
-- no cash/income report reads invoice money columns other than
-- draft_received_amount, so an allocation stored this way CANNOT be
-- double-counted as new income no matter which report is written next. Had the
-- allocation been written as a `payments` row instead, every report that sums
-- `payments WHERE status='approved'` (accountant/financial-summary.php,
-- doctors/daily-summary.php, doctors/profile.php, doctors/report.php,
-- doctors/report-pdf.php) would have counted the same money a second time.
--
-- The two columns mean different things and must not be confused:
--   prior_paid_amount   — paid before this system existed, outside it. Never
--                         was in any cash drawer here.
--   advance_paid_amount — paid at this counter, on a real day, with a real
--                         receipt. Already in the cash drawer for that day.

-- ── 1. The counter receipt ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS patient_advances (
    id INT AUTO_INCREMENT PRIMARY KEY,
    advance_no VARCHAR(30) NOT NULL,
    patient_id INT NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    payment_method ENUM('cash','card','transfer','other') NOT NULL DEFAULT 'cash',
    notes TEXT NULL,
    received_by INT NULL,
    received_at DATETIME NOT NULL,
    -- A refund is money OUT on the day it is given back, never an edit of the
    -- original receipt: the morning's cash total must stay what it was.
    refunded_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    refunded_by INT NULL,
    refunded_at DATETIME NULL,
    refund_reason TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_patient_advances_no (advance_no),
    KEY idx_patient_advances_patient (patient_id),
    KEY idx_patient_advances_received_at (received_at),
    CONSTRAINT fk_patient_advances_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    CONSTRAINT fk_patient_advances_received_by FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_patient_advances_refunded_by FOREIGN KEY (refunded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. Which receipt paid which invoice ─────────────────────────────────
-- The audit trail behind case_invoices.advance_paid_amount. The column is what
-- every balance calculation reads (no join, same shape as prior_paid_amount);
-- this table is how we know WHICH morning receipt funded it, and it is what
-- the patient's available balance is computed from.
CREATE TABLE IF NOT EXISTS patient_advance_allocations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    advance_id INT NOT NULL,
    patient_id INT NOT NULL,
    case_id INT NOT NULL,
    -- INT UNSIGNED, not INT: case_invoices.id is UNSIGNED
    -- (2026_08_23_case_invoices.sql), and a foreign key whose type does not
    -- match the column it points at is rejected outright with errno 150.
    -- cases.id/patients.id/users.id are all plain signed INT.
    invoice_id INT UNSIGNED NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    allocated_by INT NULL,
    allocated_at DATETIME NOT NULL,
    -- Undo leaves the row in place so the history still shows it happened.
    reversed_at DATETIME NULL,
    reversed_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_advance_alloc_advance (advance_id),
    KEY idx_advance_alloc_patient (patient_id),
    KEY idx_advance_alloc_invoice (invoice_id),
    CONSTRAINT fk_advance_alloc_advance FOREIGN KEY (advance_id) REFERENCES patient_advances(id) ON DELETE CASCADE,
    CONSTRAINT fk_advance_alloc_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    CONSTRAINT fk_advance_alloc_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
    CONSTRAINT fk_advance_alloc_invoice FOREIGN KEY (invoice_id) REFERENCES case_invoices(id) ON DELETE CASCADE,
    CONSTRAINT fk_advance_alloc_by FOREIGN KEY (allocated_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_advance_alloc_reversed_by FOREIGN KEY (reversed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. The invoice-side figure every balance already knows how to read ──
ALTER TABLE case_invoices ADD COLUMN advance_paid_amount DECIMAL(15,2) NOT NULL DEFAULT 0;

-- ── 4. Same view as 2026_09_14_historical_cases.sql, with the allocated
--       advance counted as paid alongside chair cash and دراوی پێشوو. ──────
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
              + GREATEST(0,COALESCE(ci.advance_paid_amount,0))
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
                  + GREATEST(0,COALESCE(ci.advance_paid_amount,0))
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
