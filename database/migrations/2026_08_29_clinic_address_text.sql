-- Correct the clinic address printed on every patient-facing document
-- (cases/receipt.php, cases/voucher.php, xray/receipt.php).
-- The settings row still held the install-time placeholder on live, so the
-- x-ray receipt printed "هەولێر - عیراق" while the case receipt fell back to a
-- hardcoded string that carried the old typos.

UPDATE settings
   SET setting_value = 'سۆران: ترافیک لایەتی دەروازەی سۆران، بەرامبەر میلان کڵاس'
 WHERE setting_key = 'clinic_address'
   AND (setting_value IS NULL
        OR TRIM(setting_value) = ''
        OR TRIM(setting_value) IN ('هەولێر - عیراق', 'Erbil - Iraq')
        OR setting_value LIKE '%ترافیک لایەنی%'
        OR setting_value LIKE '%تراقیک لایێنی%'
        OR setting_value LIKE '%میلان کلاس%');
