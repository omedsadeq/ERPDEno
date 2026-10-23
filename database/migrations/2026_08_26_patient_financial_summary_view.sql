-- Patient-level roll-up of the case financial summary, for the accounting
-- approval center's patient list (spec). X-ray outstanding and credit are
-- deliberately NOT in this view — PatientFinance composes them in PHP.
CREATE OR REPLACE VIEW v_patient_financial_summary AS
SELECT p.id AS patient_id,
       COALESCE(SUM(s.total_invoiced), 0) AS total_invoiced,
       COALESCE(SUM(s.total_discount), 0) AS total_discount,
       COALESCE(SUM(s.approved_amount), 0) AS approved_amount,
       COALESCE(SUM(s.paid_amount), 0) AS paid_amount,
       COALESCE(SUM(s.balance), 0) AS case_balance
FROM patients p
LEFT JOIN patient_folders pf ON pf.patient_id = p.id
LEFT JOIN cases c ON c.folder_id = pf.id
LEFT JOIN v_case_financial_summary s ON s.case_id = c.id
GROUP BY p.id;
