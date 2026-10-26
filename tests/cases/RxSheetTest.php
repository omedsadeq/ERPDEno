<?php // tests/cases/RxSheetTest.php
// ڕەچەتە — the doctor's A5 prescription sheet. Pinned: the Code 128 table and
// checksum (the barcode a till scanner reads back), the prescription number ⇄
// barcode round trip, and a doctor's panel: clinic defaults for empty fields,
// «د.» not printed twice, a missing picture falling back to the fields, and
// what saving refuses.
require_once __DIR__ . '/../../classes/Code128.php';
require_once __DIR__ . '/../../classes/RxProfile.php';

$db = Database::getInstance();

// ── Code 128 ──
$t->assertSame(107, count(Code128::PATTERNS), 'the table has every symbol, start codes and stop');
$bad = array_filter(Code128::PATTERNS, fn($p, $i) => array_sum(str_split($p)) !== ($i === Code128::STOP ? 13 : 11), ARRAY_FILTER_USE_BOTH);
$t->assertSame([], $bad, 'every symbol is 11 modules wide (the stop 13)');
$t->assertSame(count(Code128::PATTERNS), count(array_unique(Code128::PATTERNS)), 'no two symbols share a pattern');
// «7700000011» in Code C: start C (105), 77 00 00 00 11, checksum, stop
$sym = Code128::symbols('7700000011');
$t->assertSame([105, 77, 0, 0, 0, 11], array_slice($sym, 0, 6), 'an even run of digits is packed two to a symbol (Code C)');
$t->assertSame((105 + 77 * 1 + 0 + 0 + 0 + 11 * 5) % 103, $sym[6], 'the checksum is start + Σ value × position, mod 103');
$t->assertSame(Code128::STOP, end($sym), 'it ends on the stop symbol');
$t->assertSame(Code128::START_B, Code128::symbols('RX-11')[0], 'letters use Code B');
$t->assertThrows(fn() => Code128::symbols('ڕەچەتە'), 'non-ASCII text is refused');
$svg = Code128::svg('7700000011');
$t->assertTrue(strpos($svg, '<svg') === 0 && substr_count($svg, '<rect') === 3 * 7 + 4, 'the SVG draws three bars a symbol (start, 5 pairs, checksum) and four for the stop');

// ── prescription ⇄ barcode ──
$t->assertSame('7700000123', RxProfile::barcode(123), 'prescription 123 prints as 7700000123');
$t->assertSame(123, RxProfile::barcodeId('7700000123'), '… and scans back to 123');
$t->assertSame(123, RxProfile::barcodeId(' 7700000123 '), 'a scanner\'s stray spaces do not matter');
$t->assertSame(null, RxProfile::barcodeId('6260012345678'), 'a product\'s EAN-13 is not a prescription');
$t->assertSame(null, RxProfile::barcodeId('7700000000'), 'there is no prescription 0');

// ── a doctor's panel ──
$doc = fx_user('doctor');
$db->update('users', ['full_name' => 'د.تاقیکردنەوە ' . $doc], 'id = :id', ['id' => $doc]);
$p = RxProfile::forUser($doc);
$t->assertSame('دکتۆر', $p['title'], 'the title defaults to «دکتۆر»');
$t->assertSame('تاقیکردنەوە ' . $doc, $p['display_name'], '«د.» in the account name is not printed again under the title');
$t->assertSame('fields', $p['mode'], 'no profile yet: built from the fields');
$t->assertSame(RxProfile::DEFAULT_QR1, $p['qr1_url'], 'the QR codes default to the clinic\'s pages');
$t->assertTrue($p['phones'] !== '' && $p['address'] !== [], 'phones and address default to the clinic\'s');

$t->assertThrows(fn() => RxProfile::save(fx_user('accountant'), [], null, false, 1), 'only a doctor has a prescription sheet');
$t->assertThrows(fn() => RxProfile::save($doc, ['qr1_url' => 'javascript:alert(1)'], null, false, 1), 'a QR link must be a web address');
$t->assertThrows(fn() => RxProfile::save($doc, ['panel_mode' => 'image'], null, false, 1), 'a picture panel needs a picture');

RxProfile::save($doc, ['credentials' => "یەکەم\r\n\r\nدووەم  ", 'phones' => '0750 000 0000', 'qr2_url' => 'https://example.com/x'], null, false, 1);
$p = RxProfile::forUser($doc);
$t->assertSame(['یەکەم', 'دووەم'], $p['credentials'], 'credentials: one per line, blank lines dropped');
$t->assertSame('0750 000 0000', $p['phones'], 'the doctor\'s own phones');
$t->assertSame('https://example.com/x', $p['qr2_url'], 'and own QR link');

// a picture panel whose file has gone falls back to the fields, never a hole
$db->update('doctor_rx_profiles', ['panel_mode' => 'image', 'panel_image' => 'uploads/rx/gone_' . $doc . '.jpg'], 'user_id = :id', ['id' => $doc]);
$t->assertSame('fields', RxProfile::forUser($doc)['mode'], 'a missing picture prints the fields instead');
$db->update('doctor_rx_profiles', ['panel_image' => '../config/config.php'], 'user_id = :id', ['id' => $doc]);
$t->assertSame('fields', RxProfile::forUser($doc)['mode'], 'a path outside uploads/rx is never used');
