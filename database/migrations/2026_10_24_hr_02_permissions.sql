/*
 * سەرچاوە مرۆییەکان (hr/) — permission registration.
 *
 * Same tables and same admin page (admin/role-permissions.php) as every other
 * right. Admin is absent on purpose: Permission grants admins every key.
 *
 * Defaults are CLOSED except for the accountant, who reads attendance and
 * payroll but changes nothing. Salaries are private: hr.payroll.view is the
 * only key that shows money, on the reports and on the employee form alike.
 */

INSERT IGNORE INTO permission_definitions (permission_key, permission_name, permission_name_ku, category, description) VALUES
    ('hr.employees.view',      'View Employees',            'بینینی کارمەندان',                       'hr', 'HR employee list and the HR dashboard'),
    ('hr.employees.manage',    'Manage Employees',          'زیادکردن و دەستکاریکردنی کارمەندان',      'hr', 'Add and edit employees, and their order in the monthly report'),
    ('hr.punches.manage',      'Manage Punches',            'تۆمار و دەستکاریکردنی پەنجەمۆر',          'hr', 'Add, correct and delete fingerprint punches'),
    ('hr.adjustments.manage',  'Hour Adjustments',          'زیادە و لێبڕینی کاتژمێر',                 'hr', 'Add or remove hours on a work day'),
    ('hr.reports.view',        'Attendance Reports',        'ڕاپۆرتەکانی ئامادەبوون',                   'hr', 'Monthly, per-employee, in/out and missing-punch reports'),
    ('hr.payroll.view',        'View Salaries',             'بینینی مووچە',                            'hr', 'Hourly rates, salaries and payroll totals'),
    ('hr.settings',            'HR Settings',               'ڕێکخستنی شەفت و پشوو و بەشەکان',          'hr', 'Shifts, holidays and departments');

INSERT IGNORE INTO role_permissions (role, permission_key, is_allowed) VALUES
    ('accountant',      'hr.employees.view',     1),
    ('accountant',      'hr.employees.manage',   0),
    ('accountant',      'hr.punches.manage',     0),
    ('accountant',      'hr.adjustments.manage', 0),
    ('accountant',      'hr.reports.view',       1),
    ('accountant',      'hr.payroll.view',       1),
    ('accountant',      'hr.settings',           0),

    ('receptionist',    'hr.employees.view',     0),
    ('receptionist',    'hr.employees.manage',   0),
    ('receptionist',    'hr.punches.manage',     0),
    ('receptionist',    'hr.adjustments.manage', 0),
    ('receptionist',    'hr.reports.view',       0),
    ('receptionist',    'hr.payroll.view',       0),
    ('receptionist',    'hr.settings',           0),

    ('doctor',          'hr.employees.view',     0),
    ('doctor',          'hr.employees.manage',   0),
    ('doctor',          'hr.punches.manage',     0),
    ('doctor',          'hr.adjustments.manage', 0),
    ('doctor',          'hr.reports.view',       0),
    ('doctor',          'hr.payroll.view',       0),
    ('doctor',          'hr.settings',           0),

    ('xray_department', 'hr.employees.view',     0),
    ('xray_department', 'hr.employees.manage',   0),
    ('xray_department', 'hr.punches.manage',     0),
    ('xray_department', 'hr.adjustments.manage', 0),
    ('xray_department', 'hr.reports.view',       0),
    ('xray_department', 'hr.payroll.view',       0),
    ('xray_department', 'hr.settings',           0);
