-- The pages that were admin-only by code become permissions the admin sets per
-- role on admin/role-permissions.php. No role is given one here, so nothing
-- changes until the admin ticks it (the admin always has every permission).
-- page.settings, page.users, page.permissions and page.reports already exist;
-- they are now what those pages check.

INSERT IGNORE INTO permission_definitions (permission_key, permission_name, permission_name_ku, category, description) VALUES
('page.backups',               'Database backups',            'باکئەپی داتابەیس',              'admin', 'دروستکردن و داگرتنی باکئەپ'),
('page.pdf_settings',          'PDF settings',                'ڕێکخستنی PDF',                  'admin', 'سەر و ژێری PDF ـەکان'),
('page.cancellation_reasons',  'Cancellation reasons',        'هۆکاری هەڵوەشاندنەوە',          'admin', 'هۆکارەکانی هەڵوەشاندنەوەی مەوعید و ڕەتکردنەوە'),
('page.xray_external_doctors', 'External doctors (X-ray)',    'دکتۆرانی دەرەوە',               'admin', 'دکتۆرانی دەرەوە کە نەخۆش بۆ ئێکسڕەی دەنێرن'),
('page.doctor_permissions',    'Doctor permissions',          'مۆڵەتەکانی دکتۆر',              'admin', 'مۆڵەتی تایبەتی هەر دکتۆرێک'),
('page.rx_templates',          'Prescription templates',      'قاڵبی ڕەچەتە',                  'admin', 'قاڵبی ڕەچەتە و ڕەچەتەی دکتۆرەکان'),
('page.unassigned_cases',      'Unassigned cases',            'کەیسی بێ دکتۆر',                'admin', 'ئەو کەیسانەی دکتۆریان بۆ دیاری نەکراوە'),
('page.xray_pricing',          'X-ray prices',                'نرخی ئێکسڕەی',                  'xray',  'نرخی جۆرەکانی ئێکسڕەی'),
('page.expense_reports',       'Expense reports',             'ڕاپۆرتی خەرجی',                 'expenses', 'ڕاپۆرت و چاپی خەرجییەکان');

-- «دکتۆر تەنها هی خۆی»: a doctor sees and works on his own patients, cases,
-- debts, queue and reports only — unless his role has this, then he works
-- across all doctors as the admin does (Auth::isRestrictedDoctor). Doctors
-- start without it, so nothing changes until the admin ticks it.
INSERT IGNORE INTO permission_definitions (permission_key, permission_name, permission_name_ku, category, description) VALUES
('data.all_doctors', 'Work across all doctors', 'بینین و کارکردن لەسەر هەموو دکتۆرەکان', 'doctors',
 'بۆ دکتۆر: بە بێ ئەمە تەنها نەخۆش، کەیس، قەرز، ڕیز و ڕاپۆرتی خۆی دەبینێت؛ بەمە وەک بەڕێوەبەر هی هەمووان');
INSERT IGNORE INTO role_permissions (role, permission_key, is_allowed) VALUES ('doctor', 'data.all_doctors', 0);
