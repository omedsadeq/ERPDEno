<?php
/**
 * دەرمانخانە — the one map of the module's screens.
 *
 * The sidebar shows only the main entries (one per group, plus the
 * prescriptions queue); every other screen is reached from the bar at the top
 * of each pharmacy page (erp2/_nav.php) and from the dashboard tiles. All
 * three read this file, so they can never disagree about what exists, where it
 * lives, or who may open it.
 *
 * Each item: page (basename without .php), url (relative to erp2/), label,
 * icon, and `can` — any one of those permission keys reveals it.
 *
 * Self-contained on purpose: includes/sidebar.php loads it on every page of
 * the clinic, so it must not need erp2/_bootstrap.php.
 */

require_once __DIR__ . '/../classes/Permission.php';
require_once __DIR__ . '/../includes/module_nav.php';

if (!function_exists('erp2_nav_groups')) {
    /**
     * The groups the current user may see, each with only the items he may
     * open. A group with no permitted item is dropped entirely.
     */
    function erp2_nav_groups(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $perm = Permission::getInstance();
        // Either side of the ledger opens payments / statements; land on the
        // side this user can actually use.
        $payKind  = $perm->can('erp2.payments.customer') ? 'customer' : 'supplier';
        $stmtKind = $perm->can('erp2.customers.view') ? 'customer' : 'supplier';

        $groups = [
            'home' => [
                'label' => 'داشبۆرد', 'icon' => 'fa-gauge-high', 'color' => '#0f766e',
                'items' => [
                    ['page' => 'index', 'url' => 'index.php', 'label' => 'داشبۆرد', 'icon' => 'fa-gauge-high', 'can' => ['erp2.dashboard']],
                ],
            ],
            'sales' => [
                'label' => 'فرۆشتن', 'icon' => 'fa-cash-register', 'color' => '#2e7d32',
                'items' => [
                    // The counter first: it is where the sidebar's «فرۆشتن» lands.
                    ['page' => 'pos', 'url' => 'pos.php', 'label' => 'کاشێر (POS)', 'icon' => 'fa-cash-register', 'can' => ['erp2.sales.create']],
                    ['page' => 'sales', 'url' => 'sales.php', 'label' => 'فرۆشتنی نوێ', 'icon' => 'fa-cart-plus', 'can' => ['erp2.sales.view']],
                    ['page' => 'prescriptions', 'url' => 'prescriptions.php', 'label' => 'داواکاری دەرمان', 'icon' => 'fa-prescription-bottle-medical', 'can' => ['erp2.prescriptions.view']],
                    ['page' => 'invoices', 'url' => 'invoices.php', 'label' => 'وەسڵەکان', 'icon' => 'fa-file-invoice', 'can' => ['erp2.sales.view']],
                    ['page' => 'sales-returns', 'url' => 'sales-returns.php', 'label' => 'گەڕاندنەوەی فرۆشتن', 'icon' => 'fa-rotate-left', 'can' => ['erp2.sales.return']],
                ],
            ],
            'purchase' => [
                'label' => 'کڕین', 'icon' => 'fa-truck', 'color' => '#1565c0',
                'items' => [
                    ['page' => 'purchases', 'url' => 'purchases.php', 'label' => 'کڕین', 'icon' => 'fa-truck', 'can' => ['erp2.purchase.view']],
                    ['page' => 'purchase-invoices', 'url' => 'purchase-invoices.php', 'label' => 'وەسڵەکانی کڕین', 'icon' => 'fa-file-invoice', 'can' => ['erp2.purchase.view']],
                    ['page' => 'purchase-returns', 'url' => 'purchase-returns.php', 'label' => 'گەڕاندنەوەی کڕین', 'icon' => 'fa-truck-arrow-right', 'can' => ['erp2.purchase.return']],
                ],
            ],
            'stock' => [
                'label' => 'کۆگا', 'icon' => 'fa-warehouse', 'color' => '#c2410c',
                'items' => [
                    ['page' => 'products', 'url' => 'products.php', 'label' => 'کاڵاکان', 'icon' => 'fa-boxes-stacked', 'can' => ['erp2.products.view']],
                    ['page' => 'dosages', 'url' => 'dosages.php', 'label' => 'دۆز و ڕێنمایی', 'icon' => 'fa-prescription', 'can' => ['erp2.dosages.manage']],
                    ['page' => 'stock', 'url' => 'stock.php', 'label' => 'بڕی کۆگا', 'icon' => 'fa-warehouse', 'can' => ['erp2.products.view']],
                    ['page' => 'movements', 'url' => 'movements.php', 'label' => 'جوڵەی کۆگا', 'icon' => 'fa-clock-rotate-left', 'can' => ['erp2.products.view']],
                    ['page' => 'transfers', 'url' => 'transfers.php', 'label' => 'گواستنەوە', 'icon' => 'fa-right-left', 'can' => ['erp2.stock.transfer']],
                    ['page' => 'adjustments', 'url' => 'adjustments.php', 'label' => 'ڕێکخستنی کۆگا', 'icon' => 'fa-clipboard-check', 'can' => ['erp2.stock.adjust']],
                    ['page' => 'warehouses', 'url' => 'warehouses.php', 'label' => 'کۆگاکان', 'icon' => 'fa-building', 'can' => ['erp2.products.view']],
                    ['page' => 'settings', 'url' => 'settings.php', 'label' => 'ڕێکخستنەکان', 'icon' => 'fa-sliders', 'can' => ['erp2.settings']],
                ],
            ],
            'accounts' => [
                'label' => 'حساب و ڕاپۆرت', 'icon' => 'fa-scale-balanced', 'color' => '#0e7490',
                'items' => [
                    ['page' => 'customers', 'url' => 'customers.php', 'label' => 'کڕیارەکان', 'icon' => 'fa-user-tag', 'can' => ['erp2.customers.view']],
                    ['page' => 'suppliers', 'url' => 'suppliers.php', 'label' => 'دابینکەرەکان', 'icon' => 'fa-industry', 'can' => ['erp2.suppliers.view']],
                    ['page' => 'payments', 'url' => 'payments.php?kind=' . $payKind, 'label' => 'پارەدان', 'icon' => 'fa-hand-holding-dollar', 'can' => ['erp2.payments.customer', 'erp2.payments.supplier']],
                    ['page' => 'statement', 'url' => 'statement.php?kind=' . $stmtKind . '&id=0', 'label' => 'کشف حساب', 'icon' => 'fa-file-lines', 'can' => ['erp2.customers.view', 'erp2.suppliers.view']],
                    ['page' => 'reports', 'url' => 'reports.php', 'label' => 'ڕاپۆرتەکان', 'icon' => 'fa-chart-column', 'can' => ['erp2.reports.view']],
                ],
            ],
        ];

        $cache = module_nav_filter($groups);
        return $cache;
    }

    /** The group a page belongs to, or null when it is not a pharmacy screen. */
    function erp2_nav_group_of(string $page): ?string
    {
        return module_nav_group_of(erp2_nav_groups(), $page);
    }

    /** Prescriptions waiting at the counter. Fail-soft: 0 while migrating. */
    function erp2_nav_pending_prescriptions(): int
    {
        static $count = null;
        if ($count === null) {
            try {
                $count = (int)(Database::getInstance()->fetch(
                    "SELECT COUNT(*) AS cnt FROM erp2_prescriptions WHERE status = 'pending'"
                )['cnt'] ?? 0);
            } catch (Throwable $e) {
                $count = 0;
            }
        }
        return $count;
    }
}
