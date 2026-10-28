<?php // tests/cases/WorkTypeIconsTest.php
// A work type's icon (work_types.icon, the «جۆری ئیش» cards on the new-visit
// form): the keys the server accepts are exactly the icons
// assets/js/treatment-icons.js draws — the treatment set, then the complaint
// set — an unknown key is stored as no icon, and the column is there.
$db = Database::getInstance();

preg_match_all("/\\{ key: '([a-z_]+)'/", file_get_contents(__DIR__ . '/../../assets/js/treatment-icons.js'), $m);
$t->assertSame(WORK_TYPE_ICONS, $m[1], 'the server accepts exactly the icons the cards draw, in the same order');

$t->assertSame('pain', work_type_icon('pain'), 'a complaint icon is kept');
$t->assertSame('crown', work_type_icon(' crown '), 'a treatment icon is kept, stray spaces or not');
$t->assertSame(null, work_type_icon('<svg>'), 'an unknown key is no icon');
$t->assertSame(null, work_type_icon(null), 'no key is no icon');

$col = $db->fetch("SHOW COLUMNS FROM work_types LIKE 'icon'");
$t->assertTrue(!empty($col), 'work_types has an icon column');

$id = (int)$db->insert('work_types', [
    'work_key' => 'tst_icon_' . mt_rand(1000, 9999), 'work_name' => 'icon test',
    'icon' => work_type_icon('broken'), 'is_active' => 0, 'sort_order' => 0,
]);
$t->assertSame('broken', $db->fetch("SELECT icon FROM work_types WHERE id = :id", ['id' => $id])['icon'], 'the icon is stored with the type');
$db->delete('work_types', 'id = :id', ['id' => $id]);
