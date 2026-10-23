<?php
/**
 * Shared bootstrap for every ERP page.
 *
 * Reuses the dental system's authentication and permission tables rather than
 * introducing a second login, per the module brief. What it adds is a single
 * place to gate ERP pages and to expose the small set of helpers the ERP
 * screens need.
 *
 * Usage, before including includes/header.php:
 *
 *     require_once __DIR__ . '/_bootstrap.php';
 *     erp2_require('erp2.products.view');
 *     $pageTitle = '...';
 *
 * ERP pages never write to dental tables, and no dental page depends on this
 * file, so this module can be removed by deleting erp2/ and its erp2_ tables.
 *
 * This is دەرمانخانە (erp2) — a full duplicate of the original erp/ module
 * generated from it (same code shape, own tables/classes/permissions) so the
 * two sections never share data.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Permission.php';
require_once __DIR__ . '/../classes/Erp2Stock.php';
require_once __DIR__ . '/../classes/Erp2Sequence.php';
require_once __DIR__ . '/../classes/Erp2Audit.php';

$erpAuth = new Auth();
$erpAuth->requireLogin();

// Every pharmacy page gets the section bar (erp2/_nav.php) from header.php;
// the sidebar keeps only the main entries.
$pageModuleNav = __DIR__ . '/_nav.php';

/** Gate a page on an ERP permission, sending refusals to 403.php. */
function erp2_require(string $permissionKey): void
{
    Permission::getInstance()->requirePageAccess($permissionKey);
}

/** Non-fatal permission test, for hiding buttons the user may not use. */
function erp2_can(string $permissionKey): bool
{
    return Permission::getInstance()->can($permissionKey);
}

/** Money for display. IQD only, no symbol, matching the dental pages. */
function erp2_money($v): string
{
    return number_format((float)$v, 0);
}

/**
 * Quantity for display. Trims trailing zeros so a piece count reads "12"
 * rather than "12.000" while 2.5 kg still reads "2.5".
 */
function erp2_qty($v): string
{
    $s = number_format((float)$v, 3, '.', ',');
    return strpos($s, '.') !== false ? rtrim(rtrim($s, '0'), '.') : $s;
}

function erp2_esc($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/** Validate a Y-m-d string, returning null when it is not a real date. */
function erp2_date(?string $v): ?string
{
    if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
        return null;
    }
    $d = DateTime::createFromFormat('!Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : null;
}

/**
 * Warehouses THIS USER may work in, default first. Cached per request.
 *
 * Filtered here rather than in each screen so every dropdown in the ERP is
 * narrowed by one change: a shop cashier assigned to the shop simply never
 * sees the main store as an option. The services enforce the same rule again
 * on save, because a dropdown is a suggestion and a hand-written POST is not.
 *
 * A user with no assignment sees everything — restriction is opt-in, see
 * Erp2UserWarehouse.
 */
function erp2_warehouses(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $all = Database::getInstance()->fetchAll(
        "SELECT id, code, name, is_default
         FROM erp2_warehouses
         WHERE is_active = 1
         ORDER BY is_default DESC, name"
    );

    require_once __DIR__ . '/../classes/Erp2UserWarehouse.php';
    $userId  = (int)($_SESSION['user_id'] ?? 0);
    $isAdmin = ($_SESSION['role'] ?? '') === 'admin';
    $allowed = $isAdmin ? null : Erp2UserWarehouse::allowedIds($userId);

    if ($allowed !== null) {
        $all = array_values(array_filter($all, fn($w) => in_array((int)$w['id'], $allowed, true)));
    }

    $cache = $all;
    return $cache;
}

/**
 * The warehouse a screen should start on.
 *
 * The clinic-wide default only wins if this user may actually use it; a shop
 * cashier opens on the shop, not on a main store they cannot sell from.
 */
function erp2_default_warehouse_id(): int
{
    $mine = erp2_warehouses();
    foreach ($mine as $w) {
        if ((int)$w['is_default'] === 1) {
            return (int)$w['id'];
        }
    }
    return $mine ? (int)$mine[0]['id'] : 0;
}

/** Guard a warehouse id coming from a request. Throws when out of bounds. */
function erp2_assert_warehouse(int $warehouseId): void
{
    require_once __DIR__ . '/../classes/Erp2UserWarehouse.php';
    Erp2UserWarehouse::assertCanUse(
        (int)($_SESSION['user_id'] ?? 0),
        $warehouseId,
        ($_SESSION['role'] ?? '') === 'admin'
    );
}
