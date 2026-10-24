<?php
/**
 * سەرچاوە مرۆییەکان — the section bar at the top of every hr/ page.
 *
 * Drawn by includes/module_nav.php from hr/_nav_map.php. Included by
 * includes/header.php because hr/_bootstrap.php sets $pageModuleNav.
 */
require_once __DIR__ . '/_nav_map.php';

// hr/reports.php is four reports on one page; it says which in $hrNavPage.
$hrPage = $hrNavPage ?? $currentPage ?? basename($_SERVER['PHP_SELF'] ?? '', '.php');

module_nav_render([
    'name'    => 'سەرچاوە مرۆییەکان',
    'icon'    => 'fa-people-group',
    'brand'   => ['#14b8a6', '#0f766e'],
    'aria'    => 'بەشەکانی سەرچاوە مرۆییەکان',
    'base'    => APP_URL . '/hr/',
    'groups'  => hr_nav_groups(),
    'current' => hr_nav_group_of($hrPage) ?? 'home',
    'page'    => $hrPage,
    'badges'  => [],
    // The everyday job now that punches are entered here: fixing or adding one.
    'quick'   => Permission::getInstance()->can('hr.punches.manage')
        ? ['url' => 'punches.php', 'label' => 'پەنجەمۆرەکان', 'icon' => 'fa-fingerprint']
        : null,
]);
