-- Reception does not see the store (کۆگا) or the official letters
-- (نوسراوی فەرمی): every store (erp/) and letters (letters/) permission is
-- turned off for the receptionist role, and so is the pharmacy's own «کۆگا»
-- group (erp2 products, stock levels, movements: erp2.products.view). Selling
-- at the pharmacy till (erp2.sales.*) and the prescriptions stay. The admin
-- can give any of them back on the permissions screen.
UPDATE role_permissions SET is_allowed = 0
WHERE role = 'receptionist' AND (permission_key LIKE 'erp.%' OR permission_key LIKE 'letters.%');

UPDATE role_permissions SET is_allowed = 0
WHERE role = 'receptionist' AND permission_key = 'erp2.products.view';
