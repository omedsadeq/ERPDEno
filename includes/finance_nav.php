<?php
/** Tabs for the finance pages, using the same map as the sidebar. */
require_once __DIR__ . '/finance_nav_map.php';

$financeGroups = finance_nav_groups();
$financePage = finance_nav_canonical_page(finance_nav_page());
$financeGroup = finance_nav_group_of($financePage);
if (!$financeGroups || !isset($financeGroups[$financeGroup])) {
    return;
}
$financeHome = reset($financeGroups)['items'][0];

module_nav_render([
    'name' => 'دارایی',
    'icon' => 'fa-coins',
    'brand' => ['#0ea5e9', '#0369a1'],
    'brand_href' => module_nav_href(APP_URL . '/', $financeHome),
    'brand_title' => 'دارایی',
    'aria' => 'بەشەکانی دارایی',
    'base' => APP_URL . '/',
    'groups' => $financeGroups,
    'current' => $financeGroup,
    'page' => $financePage,
    'badges' => finance_nav_badges($financeGroups, (int)($pendingAccountingReports ?? 0), (int)($followUpsDueCount ?? 0)),
]);
