<?php
/**
 * سەرچاوە مرۆییەکان — the one map of the HR screens.
 *
 * The sidebar (includes/sidebar.php) shows one entry per group; the bar at the
 * top of every hr/ page (hr/_nav.php) and the dashboard tiles show the rest.
 * All three read this file, so they cannot disagree about what exists or who
 * may open it. Drawn by includes/module_nav.php.
 *
 * Self-contained: the sidebar loads it on every page of the clinic, so it must
 * not need hr/_bootstrap.php.
 */

require_once __DIR__ . '/../classes/Permission.php';
require_once __DIR__ . '/../includes/module_nav.php';

if (!function_exists('hr_nav_groups')) {
    function hr_nav_groups(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = module_nav_filter([
            'home' => [
                'label' => 'داشبۆرد', 'icon' => 'fa-gauge-high', 'color' => '#0f766e',
                'items' => [
                    ['page' => 'index', 'url' => 'index.php', 'label' => 'داشبۆرد', 'icon' => 'fa-gauge-high', 'can' => ['hr.employees.view', 'hr.reports.view']],
                ],
            ],
            'employees' => [
                'label' => 'کارمەندان', 'icon' => 'fa-id-card', 'color' => '#0369a1',
                'items' => [
                    ['page' => 'employees', 'url' => 'employees.php', 'label' => 'کارمەندان', 'icon' => 'fa-id-card', 'can' => ['hr.employees.view']],
                    ['page' => 'sort-order', 'url' => 'sort-order.php', 'label' => 'ڕیزبەندی کارمەندان', 'icon' => 'fa-arrow-down-1-9', 'can' => ['hr.employees.manage']],
                    ['page' => 'device-users', 'url' => 'device-users.php', 'label' => 'بەکارهێنەرانی ئامێر', 'icon' => 'fa-users-viewfinder', 'can' => ['hr.employees.view']],
                ],
            ],
            'attendance' => [
                'label' => 'ئامادەبوون', 'icon' => 'fa-fingerprint', 'color' => '#7c3aed',
                // The live board opens full screen (hr/live.php).
                'items' => [
                    ['page' => 'live', 'url' => 'live.php', 'label' => 'ڕاستەوخۆ — ئەمڕۆ', 'icon' => 'fa-tower-broadcast', 'can' => ['hr.employees.view', 'hr.reports.view']],
                    ['page' => 'punches', 'url' => 'punches.php', 'label' => 'پەنجەمۆرەکان', 'icon' => 'fa-fingerprint', 'can' => ['hr.punches.manage']],
                    ['page' => 'adjustments', 'url' => 'adjustments.php', 'label' => 'زیادە و لێبڕینی کاتژمێر', 'icon' => 'fa-clock-rotate-left', 'can' => ['hr.adjustments.manage']],
                ],
            ],
            'reports' => [
                'label' => 'ڕاپۆرتەکان', 'icon' => 'fa-chart-column', 'color' => '#c2410c',
                // One page, several reports: hr/reports.php names the one
                // showing in $hrNavPage. The monthly sheet opens full screen.
                'items' => [
                    ['page' => 'reports', 'url' => 'reports.php?r=monthly', 'label' => 'ڕاپۆرتی مانگانە', 'icon' => 'fa-table-cells', 'can' => ['hr.reports.view']],
                    ['page' => 'reports-employee', 'url' => 'reports.php?r=employee', 'label' => 'ڕاپۆرتی کارمەند', 'icon' => 'fa-user-clock', 'can' => ['hr.reports.view']],
                    ['page' => 'reports-inout', 'url' => 'reports.php?r=inout', 'label' => 'هاتن و ڕۆیشتن', 'icon' => 'fa-right-left', 'can' => ['hr.reports.view']],
                    ['page' => 'reports-missing', 'url' => 'reports.php?r=missing', 'label' => 'پەنجەمۆری ناتەواو', 'icon' => 'fa-triangle-exclamation', 'can' => ['hr.reports.view']],
                    ['page' => 'reports-late', 'url' => 'reports.php?r=late', 'label' => 'دواکەوتن و زوو ڕۆیشتن', 'icon' => 'fa-hourglass-half', 'can' => ['hr.reports.view']],
                ],
            ],
            'settings' => [
                'label' => 'ڕێکخستنەکان', 'icon' => 'fa-sliders', 'color' => '#475569',
                'items' => [
                    ['page' => 'settings', 'url' => 'settings.php', 'label' => 'شەفت و پشوو و بەشەکان', 'icon' => 'fa-sliders', 'can' => ['hr.settings']],
                ],
            ],
        ]);
        return $cache;
    }

    function hr_nav_group_of(string $page): ?string
    {
        return module_nav_group_of(hr_nav_groups(), $page);
    }
}
