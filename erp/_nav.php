<?php
/**
 * کۆگا — the section bar at the top of every store page.
 *
 * The sidebar keeps only one entry per group; this bar carries the rest.
 * Drawn by includes/module_nav.php from erp/_nav_map.php. Included by
 * includes/header.php because erp/_bootstrap.php sets $pageModuleNav.
 */
require_once __DIR__ . '/_nav_map.php';

$stPage = $currentPage ?? basename($_SERVER['PHP_SELF'] ?? '', '.php');
// The everyday shortcut is the store's main job: handing materials to a
// doctor. A user without that right falls back to a new sale.
$stQuick = null;
foreach (erp_nav_groups()['issue']['items'] ?? [] as $stItem) {
    if ($stItem['page'] === 'doctor-issue') {
        $stQuick = ['url' => 'doctor-issue.php', 'label' => 'پێدانی مەواد', 'icon' => 'fa-hand-holding-medical'];
    }
}
if (!$stQuick) {
    foreach (erp_nav_groups()['sales']['items'] ?? [] as $stItem) {
        if ($stItem['page'] === 'sales') {
            $stQuick = ['url' => 'sales.php', 'label' => 'فرۆشتنی نوێ', 'icon' => 'fa-plus'];
        }
    }
}

module_nav_render([
    'name'    => 'کۆگا',
    'icon'    => 'fa-store',
    'brand'   => ['#0ea5e9', '#0369a1'],
    'aria'    => 'بەشەکانی کۆگا',
    'base'    => APP_URL . '/erp/',
    'groups'  => erp_nav_groups(),
    'current' => erp_nav_group_of($stPage) ?? 'home',
    'page'    => $stPage,
    'badges'  => [],
    'quick'   => $stQuick,
]);
