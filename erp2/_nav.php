<?php
/**
 * دەرمانخانە — the section bar at the top of every pharmacy page.
 *
 * The sidebar keeps only the main entries; this bar carries the rest. Drawn by
 * includes/module_nav.php from erp2/_nav_map.php, permissions included.
 * Included by includes/header.php because erp2/_bootstrap.php sets
 * $pageModuleNav, so no pharmacy page has to opt in by hand.
 */
require_once __DIR__ . '/_nav_map.php';

$rxPage = $currentPage ?? basename($_SERVER['PHP_SELF'] ?? '', '.php');
$rxPending = erp2_nav_pending_prescriptions();
$rxCanSell = false;
foreach (erp2_nav_groups()['sales']['items'] ?? [] as $rxItem) {
    if ($rxItem['page'] === 'pos') { $rxCanSell = true; }
}

module_nav_render([
    'name'    => 'دەرمانخانە',
    'icon'    => 'fa-prescription-bottle-medical',
    'brand'   => ['#14b8a6', '#0f766e'],
    'aria'    => 'بەشەکانی دەرمانخانە',
    'base'    => APP_URL . '/erp2/',
    'groups'  => erp2_nav_groups(),
    'current' => erp2_nav_group_of($rxPage) ?? 'home',
    'page'    => $rxPage,
    'badges'  => ['group:sales' => $rxPending, 'page:prescriptions' => $rxPending],
    'quick'   => $rxCanSell ? ['url' => 'pos.php', 'label' => 'کاشێر', 'icon' => 'fa-cash-register'] : null,
]);
