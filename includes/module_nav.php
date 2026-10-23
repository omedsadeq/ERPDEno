<?php
/**
 * Module section bar + dashboard hub, shared by the store modules
 * (کۆگا = erp/, دەرمانخانە = erp2/).
 *
 * A module keeps only its main entries in the sidebar; the rest of its screens
 * live on this bar at the top of every one of its pages (top row: the module
 * mark back to its dashboard, one pill per group, an everyday shortcut;
 * second row: the screens of the current group) and on its dashboard hub.
 * Each module supplies its own map — groups, items, permissions — from
 * <module>/_nav_map.php; this file only draws it.
 *
 * $m for module_nav_render():
 *   name, icon, brand => [from, to] gradient, aria
 *   brand_href, brand_title optional landing link and accessible title
 *   base     URL of the module folder, ending in '/'
 *   groups   ['key' => ['label','icon','color','items' => [['page','url','label','icon']]]]
 *   current  key of the page's group ('home' for the dashboard)
 *   page     the page's basename
 *   badges   ['group:<key>' => n, 'page:<page>' => n]
 *   quick    ['url','label','icon'] or null — hidden on its own page
 */

if (!function_exists('module_nav_render')) {
    function module_nav_esc($v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }

    /** An item's link: its own absolute 'href' (a page outside the module), else base + url. */
    function module_nav_href(string $base, array $item): string
    {
        return isset($item['href']) ? (string)$item['href'] : $base . $item['url'];
    }

    function module_nav_styles(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        ?>
<style>
.mnav {
    --mn-ink: #1f2a37; --mn-quiet: #64748b; --mn-rule: #e6ebf1;
    position: relative; margin: 0 0 18px; background: #fff; border: 1px solid var(--mn-rule); border-radius: 14px;
    box-shadow: 0 1px 2px rgba(15, 23, 42, .04), 0 6px 18px -12px rgba(15, 23, 42, .18); overflow: hidden;
}
.mnav::before { content: ""; position: absolute; inset: 0 0 auto 0; height: 3px;
    background: linear-gradient(90deg, var(--mn-accent), var(--mn-b1) 55%, var(--mn-b2)); }
.mnav-top { display: flex; align-items: center; gap: 14px; padding: 12px 14px 10px; }
.mnav-brand { display: flex; align-items: center; gap: 10px; flex-shrink: 0; text-decoration: none; color: var(--mn-ink); }
.mnav-brand:hover { text-decoration: none; }
.mnav-mark { width: 40px; height: 40px; border-radius: 11px; display: grid; place-items: center; color: #fff; font-size: 17px;
    background: linear-gradient(135deg, var(--mn-b1), var(--mn-b2)); box-shadow: 0 4px 10px -4px var(--mn-b2); }
.mnav-brand-text { display: flex; flex-direction: column; line-height: 1.25; }
.mnav-brand-text b { font-size: 14.5px; font-weight: 800; }
.mnav-brand-text small { font-size: 11.5px; color: var(--mn-quiet); }
.mnav-groups { display: flex; gap: 4px; padding: 4px; margin-inline-start: 6px; background: #f3f6f9; border-radius: 11px;
    overflow-x: auto; scrollbar-width: none; min-width: 0; }
.mnav-groups::-webkit-scrollbar { display: none; }
.mnav-group { display: inline-flex; align-items: center; gap: 7px; flex-shrink: 0; padding: 7px 13px; border-radius: 8px;
    color: var(--mn-quiet); font-size: 13px; font-weight: 600; text-decoration: none; white-space: nowrap;
    transition: background .15s ease, color .15s ease, box-shadow .15s ease; }
.mnav-group i { font-size: 13px; color: #94a3b8; transition: color .15s ease; }
.mnav-group:hover { color: var(--mn-ink); background: #fff; text-decoration: none; }
.mnav-group:hover i { color: var(--g); }
.mnav-group.is-active { color: var(--g); background: #fff; box-shadow: 0 1px 2px rgba(15, 23, 42, .08), inset 0 -2px 0 var(--g); }
.mnav-group.is-active i { color: var(--g); }
.mnav-group.is-home .mnav-group-label { display: none; }
.mnav-group.is-home { padding-inline: 11px; }
.mnav-quick { margin-inline-start: auto; flex-shrink: 0; display: inline-flex; align-items: center; gap: 8px; padding: 9px 16px;
    border-radius: 10px; background: linear-gradient(135deg, #16a34a, #15803d); color: #fff; font-size: 13px; font-weight: 700;
    text-decoration: none; white-space: nowrap; box-shadow: 0 6px 14px -8px rgba(21, 128, 61, .9);
    transition: transform .15s ease, box-shadow .15s ease; }
.mnav-quick:hover { color: #fff; text-decoration: none; transform: translateY(-1px); box-shadow: 0 8px 16px -8px rgba(21, 128, 61, .95); }
.mnav-sub { display: flex; gap: 2px; padding: 0 14px; overflow-x: auto; scrollbar-width: thin; background: #fafbfc;
    border-top: 1px solid var(--mn-rule); }
.mnav-subitem { position: relative; display: inline-flex; align-items: center; gap: 7px; flex-shrink: 0; padding: 11px 13px;
    color: var(--mn-quiet); font-size: 12.5px; font-weight: 600; text-decoration: none; white-space: nowrap; transition: color .15s ease; }
.mnav-subitem i { font-size: 12px; color: #a0aec0; }
.mnav-subitem:hover { color: var(--mn-ink); text-decoration: none; }
.mnav-subitem::after { content: ""; position: absolute; inset: auto 10px 0 10px; height: 2.5px; border-radius: 3px 3px 0 0;
    background: var(--mn-accent); transform: scaleX(0); transition: transform .18s ease; }
.mnav-subitem:hover::after { transform: scaleX(.4); }
.mnav-subitem.is-active { color: var(--mn-ink); font-weight: 700; }
.mnav-subitem.is-active i { color: var(--mn-accent); }
.mnav-subitem.is-active::after { transform: scaleX(1); }
.mnav-badge { min-width: 18px; height: 18px; padding: 0 5px; border-radius: 9px; display: inline-grid; place-items: center;
    background: #e11d48; color: #fff; font-size: 10.5px; font-weight: 700; line-height: 1; direction: ltr; }
.mnav-subitem:focus-visible, .mnav-group:focus-visible, .mnav-quick:focus-visible, .mnav-brand:focus-visible {
    outline: 2px solid var(--mn-accent); outline-offset: 2px; border-radius: 8px; }
.mhub { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 14px; margin-bottom: 22px; }
.mhub-group { background: #fff; border: 1px solid #e6ebf1; border-radius: 14px; padding: 14px; position: relative; overflow: hidden; }
.mhub-group::before { content: ""; position: absolute; inset-block: 0; inset-inline-start: 0; width: 4px; background: var(--g); }
.mhub-head { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
.mhub-icon { width: 34px; height: 34px; border-radius: 10px; display: grid; place-items: center; color: #fff; background: var(--g); font-size: 15px; }
.mhub-head b { font-size: 14px; color: #1f2a37; }
.mhub-head small { display: block; font-size: 11px; color: #94a3b8; }
.mhub-links { display: grid; grid-template-columns: repeat(auto-fill, minmax(105px, 1fr)); gap: 8px; }
.mhub-link { position: relative; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 7px;
    padding: 12px 6px; min-height: 74px; border-radius: 10px; background: #f8fafc; border: 1px solid transparent; color: #334155;
    font-size: 12px; font-weight: 600; text-align: center; line-height: 1.35; text-decoration: none;
    transition: background .15s, border-color .15s, transform .15s; }
.mhub-link i { font-size: 18px; color: var(--g); }
.mhub-link:hover { background: #fff; border-color: var(--g); transform: translateY(-1px); text-decoration: none; color: #0f172a; }
.mhub-link .mnav-badge { position: absolute; top: 6px; inset-inline-start: 6px; }
@media (max-width: 900px) {
    .mnav-brand-text { display: none; }
    .mnav-quick span { display: none; }
    .mnav-quick { padding: 9px 12px; }
}
@media (max-width: 600px) {
    .mnav-top { gap: 8px; padding: 10px; }
    .mnav-mark { width: 34px; height: 34px; font-size: 15px; }
    .mnav-groups { margin-inline-start: 0; }
    .mnav-group { padding: 7px 10px; }
    .mnav-sub { padding: 0 8px; }
}
@media (prefers-reduced-motion: reduce) {
    .mnav-group, .mnav-group i, .mnav-quick, .mnav-subitem, .mnav-subitem::after, .mhub-link { transition: none; }
}
@media print { .mnav { display: none; } }
</style>
        <?php
    }

    function module_nav_render(array $m): void
    {
        $groups = $m['groups'] ?? [];
        if (!$groups) {
            return;
        }
        $e = 'module_nav_esc';
        $current = isset($groups[$m['current'] ?? '']) ? $m['current'] : 'home';
        $group = $groups[$current] ?? null;
        $accent = $group['color'] ?? ($m['brand'][1] ?? '#0f766e');
        $badges = $m['badges'] ?? [];
        $page = (string)($m['page'] ?? '');
        $base = (string)$m['base'];
        $quick = $m['quick'] ?? null;
        $quickPage = $quick ? basename(strtok($quick['url'], '?'), '.php') : '';
        module_nav_styles();
        ?>
<nav class="mnav" aria-label="<?= $e($m['aria'] ?? $m['name']) ?>"
     style="--mn-accent: <?= $e($accent) ?>; --mn-b1: <?= $e($m['brand'][0] ?? '#14b8a6') ?>; --mn-b2: <?= $e($m['brand'][1] ?? '#0f766e') ?>;">
    <div class="mnav-top">
        <a class="mnav-brand" href="<?= $e($m['brand_href'] ?? $base . 'index.php') ?>" title="<?= $e($m['brand_title'] ?? 'داشبۆردی ' . $m['name']) ?>">
            <span class="mnav-mark"><i class="fas <?= $e($m['icon']) ?>" aria-hidden="true"></i></span>
            <span class="mnav-brand-text"><b><?= $e($m['name']) ?></b><small><?= $e($group['label'] ?? 'داشبۆرد') ?></small></span>
        </a>
        <div class="mnav-groups">
            <?php foreach ($groups as $key => $g):
                $active = $key === $current;
                $badge = (int)($badges['group:' . $key] ?? 0);
            ?>
            <a class="mnav-group<?= $active ? ' is-active' : '' ?><?= $key === 'home' ? ' is-home' : '' ?>"
               href="<?= $e(module_nav_href($base, $g['items'][0])) ?>" style="--g: <?= $e($g['color']) ?>;"
               <?= $active ? 'aria-current="true"' : '' ?> title="<?= $e($g['label']) ?>">
                <i class="fas <?= $e($g['icon']) ?>" aria-hidden="true"></i>
                <span class="mnav-group-label"><?= $e($g['label']) ?></span>
                <?php if ($badge > 0): ?><span class="mnav-badge"><?= $badge ?></span><?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php if ($quick && $page !== $quickPage): ?>
        <a class="mnav-quick" href="<?= $e($base . $quick['url']) ?>">
            <i class="fas <?= $e($quick['icon'] ?? 'fa-plus') ?>" aria-hidden="true"></i><span><?= $e($quick['label']) ?></span>
        </a>
        <?php endif; ?>
    </div>
    <?php if ($group && $current !== 'home' && count($group['items']) > 1): ?>
    <div class="mnav-sub">
        <?php foreach ($group['items'] as $item):
            $on = $item['page'] === $page;
            $count = (int)($badges['page:' . $item['page']] ?? 0);
        ?>
        <a class="mnav-subitem<?= $on ? ' is-active' : '' ?>" href="<?= $e(module_nav_href($base, $item)) ?>" <?= $on ? 'aria-current="page"' : '' ?>>
            <i class="fas <?= $e($item['icon']) ?>" aria-hidden="true"></i>
            <span><?= $e($item['label']) ?></span>
            <?php if ($count > 0): ?><span class="mnav-badge"><?= $count ?></span><?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</nav>
        <?php
    }

    /** The dashboard's grouped tiles: one card per group, its screens inside. */
    function module_nav_hub(array $groups, string $base, array $badges = []): void
    {
        unset($groups['home']);
        if (!$groups) {
            return;
        }
        $e = 'module_nav_esc';
        module_nav_styles();
        ?>
<div class="erp-sec">بەشەکان</div>
<div class="mhub">
    <?php foreach ($groups as $g): ?>
    <section class="mhub-group" style="--g: <?= $e($g['color']) ?>;">
        <div class="mhub-head">
            <span class="mhub-icon"><i class="fas <?= $e($g['icon']) ?>" aria-hidden="true"></i></span>
            <div><b><?= $e($g['label']) ?></b><small><?= count($g['items']) ?> بەش</small></div>
        </div>
        <div class="mhub-links">
            <?php foreach ($g['items'] as $item):
                $count = (int)($badges['page:' . $item['page']] ?? 0); ?>
            <a class="mhub-link" href="<?= $e(module_nav_href($base, $item)) ?>">
                <i class="fas <?= $e($item['icon']) ?>" aria-hidden="true"></i>
                <span><?= $e($item['label']) ?></span>
                <?php if ($count > 0): ?><span class="mnav-badge"><?= $count ?></span><?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endforeach; ?>
</div>
        <?php
    }

    /**
     * Filter a module's raw map down to what the current user may open; a
     * group left with no item disappears.
     */
    function module_nav_filter(array $groups): array
    {
        $perm = Permission::getInstance();
        foreach ($groups as $key => &$group) {
            $group['key'] = $key;
            $group['items'] = array_values(array_filter($group['items'], function (array $item) use ($perm): bool {
                foreach ($item['can'] as $k) {
                    if ($perm->can($k)) {
                        return true;
                    }
                }
                return false;
            }));
            if (!$group['items']) {
                unset($groups[$key]);
            }
        }
        unset($group);
        return $groups;
    }

    /** The group a page belongs to, or null. */
    function module_nav_group_of(array $groups, string $page): ?string
    {
        foreach ($groups as $key => $group) {
            foreach ($group['items'] as $item) {
                if ($item['page'] === $page) {
                    return $key;
                }
            }
        }
        return null;
    }
}
