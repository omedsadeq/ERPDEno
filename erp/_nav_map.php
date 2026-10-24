<?php
/**
 * کۆگا — the one map of the store module's screens.
 *
 * The sidebar shows only one entry per group; every other screen is on the
 * bar at the top of each store page (erp/_nav.php) and on the dashboard
 * tiles. All three read this file, so they cannot disagree about what exists
 * or who may open it. Drawn by includes/module_nav.php — the same bar the
 * pharmacy (erp2/) uses, with this module's own permissions.
 *
 * Self-contained: includes/sidebar.php loads it on every page of the clinic,
 * so it must not need erp/_bootstrap.php.
 */

require_once __DIR__ . '/../classes/Permission.php';
require_once __DIR__ . '/../includes/module_nav.php';

if (!function_exists('erp_nav_groups')) {
    function erp_nav_groups(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $perm = Permission::getInstance();
        // Either side of the ledger opens payments / statements; land on the
        // side this user can actually use.
        $payKind  = $perm->can('erp.payments.customer') ? 'customer' : 'supplier';
        $stmtKind = $perm->can('erp.customers.view') ? 'customer' : 'supplier';

        $cache = module_nav_filter([
            'home' => [
                'label' => 'داشبۆرد', 'icon' => 'fa-gauge-high', 'color' => '#0369a1',
                'items' => [
                    ['page' => 'index', 'url' => 'index.php', 'label' => 'داشبۆرد', 'icon' => 'fa-gauge-high', 'can' => ['erp.dashboard']],
                ],
            ],
            // The store's main job: materials handed to the doctors. Each
            // voucher is also the doctor's خەرجی کۆگا (ErpDoctorIssue).
            'issue' => [
                'label' => 'مەواد بۆ دکتۆر', 'icon' => 'fa-hand-holding-medical', 'color' => '#0369a1',
                'items' => [
                    ['page' => 'material-requests', 'url' => 'material-requests.php', 'label' => 'داواکاری مەواد', 'icon' => 'fa-clipboard-list', 'can' => ['erp.material_request.approve']],
                    ['page' => 'doctor-issue', 'url' => 'doctor-issue.php', 'label' => 'پێدانی مەواد', 'icon' => 'fa-hand-holding-medical', 'can' => ['erp.doctor_issue.create']],
                    ['page' => 'doctor-issues', 'url' => 'doctor-issues.php', 'label' => 'وەسڵەکانی مەواد', 'icon' => 'fa-file-medical', 'can' => ['erp.doctor_issue.view']],
                    ['page' => 'expenses-inventory', 'url' => '', 'href' => APP_URL . '/admin/expenses-inventory.php', 'label' => 'خەرجی کۆگا', 'icon' => 'fa-receipt', 'can' => ['page.expenses_inventory']],
                ],
            ],
            'sales' => [
                'label' => 'فرۆشتن', 'icon' => 'fa-cash-register', 'color' => '#2e7d32',
                'items' => [
                    ['page' => 'sales', 'url' => 'sales.php', 'label' => 'فرۆشتنی نوێ', 'icon' => 'fa-cash-register', 'can' => ['erp.sales.view']],
                    ['page' => 'invoices', 'url' => 'invoices.php', 'label' => 'وەسڵەکان', 'icon' => 'fa-file-invoice', 'can' => ['erp.sales.view']],
                    ['page' => 'sales-returns', 'url' => 'sales-returns.php', 'label' => 'گەڕاندنەوەی فرۆشتن', 'icon' => 'fa-rotate-left', 'can' => ['erp.sales.return']],
                ],
            ],
            'purchase' => [
                'label' => 'کڕین', 'icon' => 'fa-truck', 'color' => '#1565c0',
                'items' => [
                    ['page' => 'purchases', 'url' => 'purchases.php', 'label' => 'کڕین', 'icon' => 'fa-truck', 'can' => ['erp.purchase.view']],
                    ['page' => 'purchase-invoices', 'url' => 'purchase-invoices.php', 'label' => 'وەسڵەکانی کڕین', 'icon' => 'fa-file-invoice', 'can' => ['erp.purchase.view']],
                    ['page' => 'purchase-returns', 'url' => 'purchase-returns.php', 'label' => 'گەڕاندنەوەی کڕین', 'icon' => 'fa-truck-arrow-right', 'can' => ['erp.purchase.return']],
                ],
            ],
            // The module itself is «کۆگا», so its stock group is named after
            // what it manages instead of repeating the module's name.
            'stock' => [
                'label' => 'کاڵاکان', 'icon' => 'fa-boxes-stacked', 'color' => '#c2410c',
                'items' => [
                    ['page' => 'products', 'url' => 'products.php', 'label' => 'کاڵاکان', 'icon' => 'fa-boxes-stacked', 'can' => ['erp.products.view']],
                    ['page' => 'stock', 'url' => 'stock.php', 'label' => 'بڕی کۆگا', 'icon' => 'fa-warehouse', 'can' => ['erp.products.view']],
                    ['page' => 'movements', 'url' => 'movements.php', 'label' => 'جوڵەی کۆگا', 'icon' => 'fa-clock-rotate-left', 'can' => ['erp.products.view']],
                    ['page' => 'transfers', 'url' => 'transfers.php', 'label' => 'گواستنەوە', 'icon' => 'fa-right-left', 'can' => ['erp.stock.transfer']],
                    ['page' => 'adjustments', 'url' => 'adjustments.php', 'label' => 'ڕێکخستنی کۆگا', 'icon' => 'fa-clipboard-check', 'can' => ['erp.stock.adjust']],
                    ['page' => 'warehouses', 'url' => 'warehouses.php', 'label' => 'کۆگاکان', 'icon' => 'fa-building', 'can' => ['erp.products.view']],
                    ['page' => 'settings', 'url' => 'settings.php', 'label' => 'ڕێکخستنەکان', 'icon' => 'fa-sliders', 'can' => ['erp.settings']],
                ],
            ],
            'accounts' => [
                'label' => 'حساب و ڕاپۆرت', 'icon' => 'fa-scale-balanced', 'color' => '#0e7490',
                'items' => [
                    ['page' => 'customers', 'url' => 'customers.php', 'label' => 'کڕیارەکان', 'icon' => 'fa-user-tag', 'can' => ['erp.customers.view']],
                    ['page' => 'suppliers', 'url' => 'suppliers.php', 'label' => 'دابینکەرەکان', 'icon' => 'fa-industry', 'can' => ['erp.suppliers.view']],
                    ['page' => 'payments', 'url' => 'payments.php?kind=' . $payKind, 'label' => 'پارەدان', 'icon' => 'fa-hand-holding-dollar', 'can' => ['erp.payments.customer', 'erp.payments.supplier']],
                    ['page' => 'statement', 'url' => 'statement.php?kind=' . $stmtKind . '&id=0', 'label' => 'کشف حساب', 'icon' => 'fa-file-lines', 'can' => ['erp.customers.view', 'erp.suppliers.view']],
                    ['page' => 'reports', 'url' => 'reports.php', 'label' => 'ڕاپۆرتەکان', 'icon' => 'fa-chart-column', 'can' => ['erp.reports.view']],
                ],
            ],
        ]);
        return $cache;
    }

    function erp_nav_group_of(string $page): ?string
    {
        return module_nav_group_of(erp_nav_groups(), $page);
    }
}
