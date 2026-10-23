-- Grant doctors access to the three financial menu items. Each page scopes its
-- data to the doctor's OWN patients (see debts.php, installments.php,
-- accountant/debt-collection.php). Admin already has every permission implicitly.
INSERT INTO role_permissions (role, permission_key, is_allowed)
VALUES
    ('doctor', 'page.debts', 1),
    ('doctor', 'page.installments', 1),
    ('doctor', 'page.debt_collection', 1)
ON DUPLICATE KEY UPDATE is_allowed = 1, updated_at = NOW();
