-- The accountant works the approval center (ناوەندی پەسەندکردن) and the debts
-- and installments (قەرز و قیست, with debt collection) — and sees nothing of
-- the income: not the income sheet and the finance summary pages, the daily
-- report, the reports hub, the money received, the receipts list, the
-- expenses, the store and the pharmacy (کۆگا), the official letters or HR
-- (salaries). The clinical pages the approval center opens (patients, cases,
-- treatment plans, x-ray requests) stay. The admin can give any of these back
-- on the permissions screen.

-- Two pages every role saw with no permission of their own: now they have one,
-- on for the roles that had them, off for the accountant.
INSERT IGNORE INTO permission_definitions (permission_key, permission_name, permission_name_ku, category, description) VALUES
('page.reports_hub', 'Reports hub', 'پەڕەی ڕاپۆرتەکان', 'main', 'The «ڕاپۆرتەکان» page: links to the clinic''s reports.'),
('page.daily_report', 'Daily report', 'ڕاپۆرتی ڕۆژانە', 'patients', 'The daily report of patients, visits and money (patients/report.php).');
INSERT IGNORE INTO role_permissions (role, permission_key, is_allowed) VALUES
('doctor', 'page.reports_hub', 1), ('receptionist', 'page.reports_hub', 1), ('xray_department', 'page.reports_hub', 1), ('accountant', 'page.reports_hub', 0),
('doctor', 'page.daily_report', 1), ('receptionist', 'page.daily_report', 1), ('xray_department', 'page.daily_report', 1), ('accountant', 'page.daily_report', 0);

-- The accountant: everything about the income off.
UPDATE role_permissions SET is_allowed = 0
WHERE role = 'accountant' AND (
       permission_key LIKE 'erp.%'
    OR permission_key LIKE 'erp2.%'
    OR permission_key LIKE 'hr.%'
    OR permission_key LIKE 'letters.%'
    OR permission_key LIKE 'income.%'
    OR permission_key IN (
        'page.dashboard', 'page.accountant_dashboard', 'page.accountant_financial', 'page.accountant_payments',
        'page.invoices', 'page.xray_history', 'page.reports_hub', 'page.daily_report',
        'page.expenses', 'page.expenses_general', 'page.expenses_inventory', 'page.expenses_lab', 'page.expense_groups'
    )
);
