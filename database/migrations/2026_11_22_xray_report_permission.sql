-- ڕاپۆرتی ئێکسڕەی (xray/report.php): how many patients had an X-ray, who
-- they are, and what was paid, owed, left or free — for the accountant and the
-- admin (the admin always has every permission). Other roles start without it;
-- the admin can tick it on admin/role-permissions.php.
INSERT IGNORE INTO permission_definitions (permission_key, permission_name, permission_name_ku, category, description) VALUES
('page.xray_report', 'X-ray report', 'ڕاپۆرتی ئێکسڕەی', 'xray', 'نەخۆشەکانی ئێکسڕەی، دراو، قەرز، ماوە و بێ بەرامبەر — بە پاڵاوتنی پێشکەوتوو');

INSERT IGNORE INTO role_permissions (role, permission_key, is_allowed) VALUES
('accountant',      'page.xray_report', 1),
('receptionist',    'page.xray_report', 0),
('doctor',          'page.xray_report', 0),
('xray_department', 'page.xray_report', 0);
