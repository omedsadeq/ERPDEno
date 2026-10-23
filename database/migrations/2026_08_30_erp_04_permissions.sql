/*
 * ERP / Store module — permission registration.
 *
 * Reuses the existing permission_definitions + role_permissions tables rather
 * than inventing a second system, so ERP rights are managed on the same
 * admin/role-permissions page as everything else.
 *
 * Admin is intentionally absent from role_permissions: Permission::getRolePermissions()
 * grants admins every row in permission_definitions automatically. Adding
 * explicit admin rows here would be dead weight that drifts out of date.
 *
 * Defaults are deliberately CLOSED. Every non-admin role starts at 0 for the
 * money-sensitive rights (profit, price overrides, cancel). An admin opens
 * them per role on the permissions page. Granting broadly here would hand
 * every receptionist the cost and profit figures on first install.
 */

INSERT IGNORE INTO permission_definitions (permission_key, permission_name, permission_name_ku, category, description) VALUES
    ('erp.dashboard',        'ERP Dashboard',        'داشبۆردی کۆگا',            'erp', 'View the ERP dashboard'),
    ('erp.products.view',    'View Products',        'بینینی کاڵاکان',           'erp', 'View the product master'),
    ('erp.products.create',  'Create Product',       'زیادکردنی کاڵا',           'erp', 'Add new products'),
    ('erp.products.edit',    'Edit Product',         'دەستکاری کاڵا',            'erp', 'Edit existing products'),
    ('erp.products.delete',  'Delete Product',       'سڕینەوەی کاڵا',            'erp', 'Deactivate or delete products'),
    ('erp.purchase.view',    'View Purchases',       'بینینی کڕین',              'erp', 'View purchase invoices'),
    ('erp.purchase.create',  'Create Purchase',      'تۆمارکردنی کڕین',          'erp', 'Create purchase invoices'),
    ('erp.purchase.edit',    'Edit Purchase',        'دەستکاری کڕین',            'erp', 'Edit purchase invoices'),
    ('erp.purchase.return',  'Return Purchase',      'گەڕاندنەوەی کڕین',         'erp', 'Create purchase returns'),
    ('erp.sales.view',       'View Sales',           'بینینی فرۆشتن',            'erp', 'View sales invoices'),
    ('erp.sales.create',     'Create Sales',         'فرۆشتنی نوێ',              'erp', 'Create sales invoices'),
    ('erp.sales.edit',       'Edit Sales',           'دەستکاری فرۆشتن',          'erp', 'Edit sales invoices'),
    ('erp.sales.return',     'Return Sales',         'گەڕاندنەوەی فرۆشتن',       'erp', 'Create sales returns'),
    ('erp.customers.view',   'View Customers',       'بینینی کڕیارەکان',         'erp', 'View ERP customers'),
    ('erp.suppliers.view',   'View Suppliers',       'بینینی دابینکەرەکان',      'erp', 'View suppliers'),
    ('erp.payments.customer','Customer Payment',     'وەرگرتنی پارە لە کڕیار',   'erp', 'Record customer payments'),
    ('erp.payments.supplier','Supplier Payment',     'دانی پارە بە دابینکەر',    'erp', 'Record supplier payments'),
    ('erp.stock.adjust',     'Stock Adjustment',     'ڕێکخستنی کۆگا',            'erp', 'Adjust stock quantities'),
    ('erp.stock.transfer',   'Stock Transfer',       'گواستنەوەی کۆگا',          'erp', 'Transfer stock between warehouses'),
    ('erp.profit.view',      'View Profit',          'بینینی قازانج',            'erp', 'View profit and cost figures'),
    ('erp.reports.view',     'Financial Reports',    'ڕاپۆرتە داراییەکان',       'erp', 'View ERP financial reports'),
    ('erp.price.sale',       'Change Sale Price',    'گۆڕینی نرخی فرۆشتن',       'erp', 'Override the sale price on an invoice line'),
    ('erp.price.purchase',   'Change Purchase Price','گۆڕینی نرخی کڕین',         'erp', 'Override the purchase price'),
    ('erp.invoice.cancel',   'Cancel Invoice',       'هەڵوەشاندنەوەی وەسڵ',      'erp', 'Cancel a confirmed invoice'),
    ('erp.settings',         'ERP Settings',         'ڕێکخستنەکانی کۆگا',        'erp', 'Manage warehouses, units and numbering');

/*
 * Accountant: full visibility of money, no stock authority.
 */
INSERT IGNORE INTO role_permissions (role, permission_key, is_allowed) VALUES
    ('accountant', 'erp.dashboard',         1),
    ('accountant', 'erp.products.view',     1),
    ('accountant', 'erp.purchase.view',     1),
    ('accountant', 'erp.sales.view',        1),
    ('accountant', 'erp.customers.view',    1),
    ('accountant', 'erp.suppliers.view',    1),
    ('accountant', 'erp.payments.customer', 1),
    ('accountant', 'erp.payments.supplier', 1),
    ('accountant', 'erp.profit.view',       1),
    ('accountant', 'erp.reports.view',      1),
    ('accountant', 'erp.stock.adjust',      0),
    ('accountant', 'erp.stock.transfer',    0),
    ('accountant', 'erp.price.sale',        0),
    ('accountant', 'erp.price.purchase',    0),
    ('accountant', 'erp.invoice.cancel',    0),
    ('accountant', 'erp.settings',          0);

/*
 * Receptionist: runs the counter. Sells and takes customer money, but sees no
 * cost or profit and cannot alter stock or cancel a posted invoice.
 */
INSERT IGNORE INTO role_permissions (role, permission_key, is_allowed) VALUES
    ('receptionist', 'erp.dashboard',         1),
    ('receptionist', 'erp.products.view',     1),
    ('receptionist', 'erp.sales.view',        1),
    ('receptionist', 'erp.sales.create',      1),
    ('receptionist', 'erp.customers.view',    1),
    ('receptionist', 'erp.payments.customer', 1),
    ('receptionist', 'erp.profit.view',       0),
    ('receptionist', 'erp.price.sale',        0),
    ('receptionist', 'erp.sales.edit',        0),
    ('receptionist', 'erp.sales.return',      0),
    ('receptionist', 'erp.invoice.cancel',    0),
    ('receptionist', 'erp.stock.adjust',      0),
    ('receptionist', 'erp.stock.transfer',    0),
    ('receptionist', 'erp.settings',          0);

/*
 * Doctor and X-ray: look up what is in the store, nothing more.
 */
INSERT IGNORE INTO role_permissions (role, permission_key, is_allowed) VALUES
    ('doctor', 'erp.products.view', 1),
    ('doctor', 'erp.dashboard',     0),
    ('doctor', 'erp.sales.create',  0),
    ('doctor', 'erp.profit.view',   0),
    ('xray_department', 'erp.products.view', 1),
    ('xray_department', 'erp.dashboard',     0),
    ('xray_department', 'erp.profit.view',   0);
