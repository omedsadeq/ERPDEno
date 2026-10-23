<?php
/**
 * نوسراوی فەرمی — the section bar at the top of every letters/ page, drawn by
 * includes/module_nav.php. letters/_bootstrap.php sets $pageModuleNav so
 * header.php includes it.
 */
require_once __DIR__ . '/../includes/module_nav.php';

$ltPage = $currentPage ?? basename($_SERVER['PHP_SELF'] ?? '', '.php');
$ltGroups = module_nav_filter([
    'archive' => [
        'label' => 'نوسراوەکان', 'icon' => 'fa-archive', 'color' => '#1f3b63',
        'items' => [['page' => 'index', 'url' => 'index.php', 'label' => 'ئەرشیف', 'icon' => 'fa-archive', 'can' => ['letters.view']]],
    ],
    'templates' => [
        'label' => 'قاڵبەکان', 'icon' => 'fa-copy', 'color' => '#7c3aed',
        'items' => [['page' => 'templates', 'url' => 'templates.php', 'label' => 'قاڵبەکان', 'icon' => 'fa-copy', 'can' => ['letters.create']]],
    ],
    'settings' => [
        'label' => 'سەردێڕ و واژۆ', 'icon' => 'fa-stamp', 'color' => '#b45309',
        'items' => [['page' => 'settings', 'url' => 'settings.php', 'label' => 'سەردێڕ و واژۆ', 'icon' => 'fa-stamp', 'can' => ['letters.settings']]],
    ],
]);

module_nav_render([
    'name'        => 'نوسراوی فەرمی',
    'icon'        => 'fa-envelope-open-text',
    'brand'       => ['#3b6ea8', '#1f3b63'],
    'aria'        => 'بەشەکانی نوسراوی فەرمی',
    'base'        => APP_URL . '/letters/',
    'brand_href'  => APP_URL . '/letters/index.php',
    'brand_title' => 'ئەرشیفی نوسراوەکان',
    'groups'      => $ltGroups,
    // The letter form belongs with the archive it writes into.
    'current'     => module_nav_group_of($ltGroups, $ltPage) ?? 'archive',
    'page'        => $ltPage,
    'quick'       => Permission::getInstance()->can('letters.create')
        ? ['url' => 'edit.php', 'label' => 'نوسراوی نوێ', 'icon' => 'fa-plus']
        : null,
]);
