-- An invoice tab opened by an admin/accountant on a doctor's case used to
-- carry that admin as its doctor (CaseInvoice::createDraft). The cashbox could
-- not book it (no cashbox for an admin — approving it failed with
-- «دکتۆر نەدۆزرایەوە») and the doctor's income missed it. File those under the
-- case's own doctor, which is what createDraft now does for new tabs.
UPDATE case_invoices ci
JOIN cases c ON c.id = ci.case_id
JOIN patient_folders pf ON pf.id = c.folder_id
JOIN users fd ON fd.id = pf.doctor_id AND fd.role = 'doctor'
LEFT JOIN users iu ON iu.id = ci.doctor_id
SET ci.doctor_id = pf.doctor_id
WHERE ci.doctor_id IS NOT NULL AND ci.doctor_id <> 0
  AND (iu.id IS NULL OR iu.role <> 'doctor');
