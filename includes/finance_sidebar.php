<?php
/** The six finance entry points; the pages themselves carry their detail tabs. */
require_once __DIR__ . '/finance_nav_map.php';
$financeGroups = finance_nav_groups();
$financeCurrentGroup = finance_nav_group_of(finance_nav_page());
$financeBadges = finance_nav_badges($financeGroups, (int)($pendingAccountingReports ?? 0), (int)($followUpsDueCount ?? 0));
$financeBadgeTotal = (int)($financeBadges['group:approvals'] ?? 0) + (int)($financeBadges['group:debts'] ?? 0);
?>
<?php if ($financeGroups): ?>
<div class="nav-group <?= $financeCurrentGroup !== null ? 'open' : '' ?>" data-nav-section="financial">
    <div class="nav-group-header" onclick="toggleNavGroup(this)">
        <span class="nav-item-icon icon-debts"><i class="fas fa-coins"></i></span>
        <span class="nav-item-text">دارایی</span>
        <?php if ($financeBadgeTotal > 0): ?><span class="nav-item-badge"><?= $financeBadgeTotal ?></span><?php endif; ?>
        <span class="nav-group-arrow"><i class="fas fa-chevron-left"></i></span>
    </div>
    <div class="nav-group-items">
        <?php foreach ($financeGroups as $financeKey => $financeGroup):
            $financeBadge = (int)($financeBadges['group:' . $financeKey] ?? 0);
        ?>
        <a href="<?= module_nav_esc(module_nav_href(APP_URL . '/', $financeGroup['items'][0])) ?>"
           class="nav-item <?= $financeCurrentGroup === $financeKey ? 'active' : '' ?>"
           <?= $financeCurrentGroup === $financeKey ? 'aria-current="true"' : '' ?>>
            <span class="nav-item-icon" style="color:<?= module_nav_esc($financeGroup['color']) ?>;"><i class="fas <?= module_nav_esc($financeGroup['icon']) ?>"></i></span>
            <span class="nav-item-text" style="white-space:normal;line-height:1.5;"><?= module_nav_esc($financeGroup['label']) ?></span>
            <?php if ($financeBadge > 0): ?><span class="nav-item-badge"><?= $financeBadge ?></span><?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
