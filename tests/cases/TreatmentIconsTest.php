<?php // tests/cases/TreatmentIconsTest.php
// A treatment type's icon (treatments.icon): the keys the server accepts are
// exactly the icons assets/js/treatment-icons.js draws, an unknown key is
// stored as no icon, and the column is there. Stars (★ favourites) are per user.
require_once __DIR__ . '/../../classes/Treatment.php';

$db = Database::getInstance();

preg_match_all("/\\{ key: '([a-z_]+)'/", file_get_contents(__DIR__ . '/../../assets/js/treatment-icons.js'), $m);
$t->assertSame(Treatment::ICONS, $m[1], 'the server accepts exactly the icons the picker draws, in the same order');

$t->assertSame('crown', Treatment::cleanIcon('crown'), 'a known icon is kept');
$t->assertSame('implant', Treatment::cleanIcon(' implant '), 'stray spaces do not matter');
$t->assertSame(null, Treatment::cleanIcon('<svg>'), 'an unknown key is no icon');
$t->assertSame(null, Treatment::cleanIcon(''), 'an empty key is no icon');

$col = $db->fetch("SHOW COLUMNS FROM treatments LIKE 'icon'");
$t->assertTrue(!empty($col), 'treatments has an icon column');

$id = (int)$db->insert('treatments', [
    'treatment_code' => 'TST_ICON_' . mt_rand(1000, 9999), 'treatment_name' => 'icon test',
    'category' => 'other', 'icon' => Treatment::cleanIcon('xray'), 'default_price' => 0, 'is_active' => 0,
]);
$t->assertSame('xray', $db->fetch("SELECT icon FROM treatments WHERE id = :id", ['id' => $id])['icon'], 'the icon is stored with the type');
$db->delete('treatments', 'id = :id', ['id' => $id]);

// ★ favourites are each user's own
$tm = new Treatment();
$u1 = fx_user('doctor'); $u2 = fx_user('accountant');
$fav = (int)$db->insert('treatments', [
    'treatment_code' => 'TST_FAV_' . mt_rand(1000, 9999), 'treatment_name' => 'fav test',
    'category' => 'other', 'default_price' => 0, 'is_active' => 1,
]);
$tm->setFavorite($u1, $fav, true);
$tm->setFavorite($u1, $fav, true);
$t->assertSame([$fav], array_values(array_filter($tm->favoriteIds($u1), fn($i) => $i === $fav)), 'a star is kept once, however often it is clicked');
$t->assertSame(false, in_array($fav, $tm->favoriteIds($u2), true), "another user's picker is not affected");
$tm->setFavorite($u1, $fav, false);
$t->assertSame(false, in_array($fav, $tm->favoriteIds($u1), true), 'unstarring removes it');
$db->delete('treatments', 'id = :id', ['id' => $fav]);
