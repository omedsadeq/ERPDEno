<?php
/**
 * نوسراوی فەرمی — draws the A4 letter.
 *
 * Pure functions: no database, no session. letters/print.php, the live
 * preview (letters/preview.php) and the PDF download (letters/pdf.php) all
 * call these, so the screen, the printer and the PDF show the same letter.
 *
 *   $L    a letter row (official_letters columns)
 *   $cfg  OfficialLetter::settings()
 *   $img  kind => image source or null (logo, header, footer, signature,
 *         seal): URLs for the browser, file paths for mPDF.
 *
 * The letter is a list of blocks (letterhead, number/date, بۆ, بابەت, …).
 * In the browser each block is a row of a table whose <thead>/<tfoot> are
 * blank spacers: browsers repeat those on every printed page, which keeps the
 * top margin and the room under the fixed footer on page 2+ as well. mPDF
 * gets the same blocks one after another, with its own page footer.
 *
 * Two designs (the letter's `design`, else letter_default_design):
 *   classic  Kurdish labels, the header/footer from the settings
 *   modern   the clinic's designed letterhead — English Number / Date /
 *            Subject, the logo faded behind the text and a coloured wave
 *            footer with the phones, a QR code and the address
 */

function lt_e($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** 123 → ١٢٣ when the letterhead is set to Kurdish digits. */
function lt_digits(string $s, string $mode): string
{
    if ($mode !== 'ku') {
        return $s;
    }
    return strtr($s, ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤',
                      '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']);
}

/** Kurdish year: Gregorian + 700 from Newroz (21 March), + 699 before it. */
function lt_kurdish_year(string $date): int
{
    $ts = strtotime($date) ?: time();
    $y = (int)date('Y', $ts);
    return date('md', $ts) >= '0321' ? $y + 700 : $y + 699;
}

/** Non-empty trimmed lines of a multi-line setting/field. */
function lt_lines($text): array
{
    $lines = preg_split('/\r\n|\r|\n/', (string)$text);
    return array_values(array_filter(array_map('trim', $lines), function ($l) { return $l !== ''; }));
}

/**
 * The body as paragraphs: a blank line starts a new paragraph, a single
 * newline is a line break. «ئاماژە بە …» opens the first paragraph when a
 * reference was given. $mark highlights template blanks like [ناوی نەخۆش].
 */
function lt_paragraphs(string $body, string $reference, bool $mark): array
{
    $body = trim(str_replace(["\r\n", "\r"], "\n", $body));
    $out = [];
    foreach ($body === '' ? [] : preg_split('/\n[ \t]*\n+/', $body) as $p) {
        $p = trim($p);
        if ($p === '') {
            continue;
        }
        $html = lt_e($p);
        if ($mark) {
            $html = preg_replace('/\[[^\]\n]{1,80}\]/u', '<mark>$0</mark>', $html);
        }
        $out[] = nl2br($html, false);
    }
    $reference = trim($reference);
    if ($reference !== '') {
        $ref = 'ئاماژە بە ' . lt_e($reference) . '، ';
        if ($out) {
            $out[0] = $ref . $out[0];
        } else {
            $out[] = $ref;
        }
    }
    return $out;
}

/** «- » before each line unless the writer already put a dash or bullet. */
function lt_list_html(string $heading, $text): string
{
    $lines = lt_lines($text);
    if (!$lines) {
        return '';
    }
    $html = '<div class="lt-list"><div class="lt-list-h">' . lt_e($heading) . '</div>';
    foreach ($lines as $line) {
        $html .= '<div>' . (preg_match('/^[-–•*]/u', $line) ? '' : '- ') . lt_e($line) . '</div>';
    }
    return $html . '</div>';
}

/** The design a letter is drawn in: its own, else the default from the settings. */
function lt_design(array $L, array $cfg): string
{
    $design = (string)($L['design'] ?? '');
    return in_array($design, ['classic', 'modern'], true) ? $design : $cfg['letter_default_design'];
}

/** 'rtl' when the text has any Arabic-script letter (Kurdish, Arabic), else 'ltr'. */
function lt_dir(string $text): string
{
    return preg_match('/\p{Arabic}/u', $text) ? 'rtl' : 'ltr';
}

/** $hex moved $t of the way towards white (0 = unchanged, 1 = white). */
function lt_tint(string $hex, float $t): string
{
    $out = '#';
    foreach (str_split(ltrim($hex, '#'), 2) as $pair) {
        $c = hexdec($pair);
        $out .= sprintf('%02x', (int)round($c + (255 - $c) * $t));
    }
    return $out;
}

/** Points from the configured body size in px. */
function lt_pt(array $cfg, float $factor = 1.0): string
{
    return rtrim(rtrim(number_format((int)$cfg['letter_font_size'] * 0.75 * $factor, 2, '.', ''), '0'), '.') . 'pt';
}

/** Height (mm) kept free at the bottom of every page for the footer. */
function lt_footer_space(array $cfg, array $img, array $opts): float
{
    if (($opts['design'] ?? 'classic') === 'modern') {
        return lt_modern_band_height($img) + 4;
    }
    $mode = $cfg['letter_footer_mode'];
    if ($mode === 'image' && !empty($img['footer'])) {
        $ratio = (float)($opts['footer_ratio'] ?? 0.12);
        return 8 + min(30, 174 * $ratio) + 4;
    }
    if ($mode === 'text' && lt_lines($cfg['letter_footer_text'])) {
        return 8 + count(lt_lines($cfg['letter_footer_text'])) * 4.6 + 5;
    }
    return 14;
}

function lt_footer_html(array $cfg, array $img, array $opts): string
{
    if (($opts['design'] ?? 'classic') === 'modern') {
        return lt_modern_footer_html($cfg, $img);
    }
    $accent = $cfg['letter_accent_color'];
    if ($cfg['letter_footer_mode'] === 'image' && !empty($img['footer'])) {
        $ratio = (float)($opts['footer_ratio'] ?? 0.12);
        $h = min(30, 174 * $ratio);
        return '<div style="text-align:center"><img src="' . lt_e($img['footer']) . '" style="height:' . round($h, 1) . 'mm;max-width:100%" alt=""></div>';
    }
    $lines = $cfg['letter_footer_mode'] === 'text' ? lt_lines($cfg['letter_footer_text']) : [];
    if (!$lines) {
        return '';
    }
    $html = '<div style="border-top:0.4mm solid ' . $accent . ';padding-top:1.5mm;text-align:center;font-size:8.5pt;color:#444;line-height:1.55">';
    foreach ($lines as $line) {
        $html .= '<div>' . lt_e($line) . '</div>';
    }
    return $html . '</div>';
}

/**
 * The letter's blocks, top to bottom. Each is ['html' => …, 'keep' => bool]
 * — keep = never split across two pages (the signature with its closing).
 */
function lt_blocks(array $L, array $cfg, array $img, array $opts = []): array
{
    if (($opts['design'] ?? lt_design($L, $cfg)) === 'modern') {
        return lt_modern_blocks($L, $cfg, $img, $opts);
    }
    $digits = $cfg['letter_digits'];
    $mark = !empty($opts['preview']);
    $blocks = [];

    // ── Letterhead ──
    if ($cfg['letter_header_mode'] === 'image' && !empty($img['header'])) {
        $blocks[] = ['html' => '<div class="lt-head-img"><img src="' . lt_e($img['header']) . '" alt=""></div>', 'keep' => true];
    } else {
        $side = function ($text) {
            $html = '';
            foreach (lt_lines($text) as $i => $line) {
                $html .= '<div class="' . ($i === 0 ? 'lt-head-t1' : 'lt-head-t2') . '">' . lt_e($line) . '</div>';
            }
            return $html;
        };
        $mid = '';
        if ($cfg['letter_show_logo'] === '1' && !empty($img['logo'])) {
            $mid .= '<img class="lt-logo" src="' . lt_e($img['logo']) . '" alt="">';
        }
        foreach (lt_lines($cfg['letter_header_center']) as $line) {
            $mid .= '<div class="lt-head-mid-t">' . lt_e($line) . '</div>';
        }
        $blocks[] = ['keep' => true, 'html' =>
            '<div class="lt-head' . ($cfg['letter_header_line'] === '1' ? ' lt-head-line' : '') . '">'
            . '<table class="lt-head-tbl"><tr>'
            . '<td class="lt-head-side lt-head-r">' . $side($cfg['letter_header_right']) . '</td>'
            . '<td class="lt-head-mid">' . $mid . '</td>'
            . '<td class="lt-head-side lt-head-l" dir="ltr">' . $side($cfg['letter_header_left']) . '</td>'
            . '</tr></table></div>'];
    }

    // ── ژمارە / ڕێکەوت ──
    $date = (string)($L['letter_date'] ?? date('Y-m-d'));
    $dateTxt = lt_digits(date('Y/m/d', strtotime($date) ?: time()), $digits);
    $number = trim((string)($L['letter_number'] ?? ''));
    $dateHtml = '<span class="lt-label">ڕێکەوت: </span><bdi>' . lt_e($dateTxt) . '</bdi>';
    if ($cfg['letter_kurdish_year'] === '1') {
        $dateHtml .= 'ی زایینی<br><bdi>'
            . lt_e(lt_digits((string)lt_kurdish_year($date), $digits)) . '</bdi>ی کوردی';
    }
    $blocks[] = ['keep' => true, 'html' =>
        '<table class="lt-meta"><tr>'
        . '<td class="lt-meta-r"><span class="lt-label">ژمارە: </span><bdi>' . ($number === '' ? '......' : lt_e(lt_digits($number, $digits))) . '</bdi></td>'
        . '<td class="lt-meta-l">' . $dateHtml . '</td>'
        . '</tr></table>'];

    // ── بۆ / بابەت ── (no label added when the writer already typed one)
    $recipient = trim((string)($L['recipient'] ?? ''));
    $toLabel = preg_match('/^بۆ[\s\/:]/u', $recipient) ? '' : 'بۆ / ';
    $blocks[] = ['keep' => true, 'html' => '<div class="lt-to">' . lt_e($toLabel) . lt_e($recipient) . '</div>'];

    $subject = trim((string)($L['subject'] ?? ''));
    $subLabel = preg_match('/^بابەت[\s\/:]/u', $subject) ? '' : 'بابەت / ';
    $blocks[] = ['keep' => true, 'html' => '<div class="lt-subject">' . lt_e($subLabel) . lt_e($subject) . '</div>'];

    // ── سڵاو، دەق ──
    $greeting = trim((string)($L['greeting'] ?? ''));
    if ($greeting !== '') {
        $blocks[] = ['keep' => true, 'html' => '<div class="lt-greet">' . lt_e($greeting) . '</div>'];
    }
    foreach (lt_paragraphs((string)($L['body'] ?? ''), (string)($L['reference_text'] ?? ''), $mark) as $p) {
        $blocks[] = ['keep' => false, 'html' => '<div class="lt-p">' . $p . '</div>'];
    }

    // ── کۆتایی + واژۆ ──
    $closing = trim((string)($L['closing'] ?? ''));
    $images = '';
    if (!empty($L['show_signature']) && !empty($img['signature'])) {
        $images .= '<img class="lt-sign-img" src="' . lt_e($img['signature']) . '" alt="">';
    }
    if (!empty($L['show_seal']) && !empty($img['seal'])) {
        $images .= '<img class="lt-seal-img" src="' . lt_e($img['seal']) . '" alt="">';
    }
    $cell = '';
    if ($closing !== '') {
        $cell .= '<div class="lt-closing">' . lt_e($closing) . '</div>';
    }
    $cell .= $images !== '' ? '<div class="lt-sign-imgs">' . $images . '</div>' : '';
    $name = trim((string)($L['signer_name'] ?? ''));
    $nameClass = 'lt-sign-name' . ($images === '' ? ' lt-sign-gap' : '');
    $cell .= '<div class="' . $nameClass . '">' . ($name !== '' ? lt_e($name) : '&nbsp;') . '</div>';
    foreach (lt_lines($L['signer_title'] ?? '') as $line) {
        $cell .= '<div class="lt-sign-title">' . lt_e($line) . '</div>';
    }
    $sig = '<td class="lt-sign-cell">' . $cell . '</td>';
    switch ($cfg['letter_signature_side']) {
        case 'right':  $row = $sig . '<td class="lt-sign-pad"></td>'; break;
        case 'center': $row = '<td class="lt-sign-pad2"></td>' . $sig . '<td class="lt-sign-pad2"></td>'; break;
        default:       $row = '<td class="lt-sign-pad"></td>' . $sig; break;
    }
    $blocks[] = ['keep' => true, 'html' => '<table class="lt-sign"><tr>' . $row . '</tr></table>'];

    // ── هاوپێچ / وێنەیەک بۆ ──
    $att = lt_list_html('هاوپێچ:', $L['attachments'] ?? '');
    $cc = lt_list_html('وێنەیەک بۆ:', $L['cc_list'] ?? '');
    if ($att !== '' || $cc !== '') {
        $blocks[] = ['keep' => true, 'html' => $att . $cc];
    }
    return $blocks;
}

// ── The modern design ─────────────────────────────────────────────────

/** Small white glyphs for the footer band (Material icons, 24×24). */
const LTM_ICONS = [
    'phone' => 'M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z',
    'pin'   => 'M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z',
];

function lt_svg_uri(string $svg): string
{
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

/** A footer glyph in a soft circle, as an <img> both browsers and mPDF draw. */
function lt_modern_icon(string $name, string $color): string
{
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 40 40">'
        . '<circle cx="20" cy="20" r="19" fill="' . lt_tint($color, 0.22) . '"/>'
        . '<g transform="translate(8 8)"><path fill="#ffffff" d="' . LTM_ICONS[$name] . '"/></g></svg>';
    return '<img class="ltm-icon" src="' . lt_svg_uri($svg) . '" alt="">';
}

/** Height (mm) of the coloured band — taller when it carries the QR code. */
function lt_modern_band_height(array $img): float
{
    return !empty($img['qr']) ? 40.0 : 27.0;
}

/** The wave band itself: full page width, sitting on the bottom edge. */
function lt_modern_band_svg(array $cfg, array $img): string
{
    $c = $cfg['letter_modern_color'];
    $h = lt_modern_band_height($img);
    return '<svg xmlns="http://www.w3.org/2000/svg" width="210mm" height="' . $h . 'mm" viewBox="0 0 210 ' . $h . '" preserveAspectRatio="none">'
        . '<path d="M0 6.2 C 48 -0.6, 118 11.5, 210 1.4" fill="none" stroke="' . lt_tint($c, 0.55) . '" stroke-width="0.7"/>'
        . '<path d="M0 9.4 C 52 2.6, 122 14.6, 210 4.6 L210 ' . $h . ' L0 ' . $h . ' Z" fill="' . $c . '"/>'
        . '</svg>';
}

/** What sits on the band: address (right), QR (middle), phones (left). */
function lt_modern_footer_html(array $cfg, array $img): string
{
    $lines = function ($text, string $class) {
        $html = '';
        foreach (lt_lines($text) as $line) {
            $html .= '<div class="' . $class . '">' . lt_e($line) . '</div>';
        }
        return $html;
    };
    $address = $lines($cfg['letter_modern_address'], 'ltm-foot-addr');
    $phones = $lines($cfg['letter_modern_contacts'], 'ltm-foot-tel');
    // A white cell around the code: scanners need that light quiet zone.
    $qr = !empty($img['qr'])
        ? '<table class="ltm-qr" align="center"><tr><td class="ltm-qr-cell"><img class="ltm-qr-img" src="' . lt_e($img['qr']) . '" alt=""></td></tr></table>'
        : '';
    // mPDF styles only what a class names directly (no inheritance into
    // table cells, no «.a .b»), so every piece carries its own class.
    $pair = function (string $icon, string $text, string $dir) {
        $side = $dir === 'rtl' ? 'right' : 'left';
        return $text === '' ? '' : '<table class="ltm-pair" dir="' . $dir . '" align="' . $side . '"><tr>'
            . '<td class="ltm-pair-i">' . $icon . '</td><td class="ltm-pair-t">' . $text . '</td></tr></table>';
    };
    $c = $cfg['letter_modern_color'];
    return '<table class="ltm-foot"><tr>'
        . '<td class="ltm-foot-r" align="right">' . $pair(lt_modern_icon('pin', $c), $address, 'rtl') . '</td>'
        . '<td class="ltm-foot-mid" align="center">' . $qr . '</td>'
        . '<td class="ltm-foot-l" align="left">' . $pair(lt_modern_icon('phone', $c), $phones, 'ltr') . '</td>'
        . '</tr></table>';
}

/** «هاوپێچ» / «وێنەیەک بۆ» in the language the list itself is written in. */
function lt_modern_list(string $kuHeading, string $enHeading, $text): string
{
    $dir = lt_dir((string)$text);
    $html = lt_list_html($dir === 'rtl' ? $kuHeading : $enHeading, $text);
    return $html === '' ? '' : '<div class="ltm-' . $dir . '" dir="' . $dir . '">' . $html . '</div>';
}

/** The modern sheet's blocks — same contract as lt_blocks(). */
function lt_modern_blocks(array $L, array $cfg, array $img, array $opts): array
{
    $mark = !empty($opts['preview']);
    $blocks = [];

    // ── Letterhead: Kurdish right, logo middle, English left, a bar under ──
    $side = function ($text, string $lang) {
        $html = '';
        foreach (lt_lines($text) as $i => $line) {
            $html .= '<div class="ltm-' . ($i === 0 ? 'h1' : 'h2') . '-' . $lang . '">' . lt_e($line) . '</div>';
        }
        return $html;
    };
    $logo = !empty($img['logo']) ? '<img class="ltm-logo" src="' . lt_e($img['logo']) . '" alt="">' : '';
    $blocks[] = ['keep' => true, 'html' =>
        '<table class="ltm-head"><tr>'
        . '<td class="ltm-head-r" align="right">' . $side($cfg['letter_modern_header_right'], 'ku') . '</td>'
        . '<td class="ltm-head-mid" align="center">' . $logo . '</td>'
        . '<td class="ltm-head-l" align="left" dir="ltr">' . $side($cfg['letter_modern_header_left'], 'en') . '</td>'
        . '</tr></table><div class="ltm-bar"></div>'];

    // ── Number / Date — English, on the left ──
    $date = (string)($L['letter_date'] ?? date('Y-m-d'));
    $number = trim((string)($L['letter_number'] ?? ''));
    $blocks[] = ['keep' => true, 'html' =>
        '<div class="ltm-meta ltm-latin" dir="ltr">'
        . '<div>Number : <bdi>' . ($number === '' ? '......' : lt_e($number)) . '</bdi></div>'
        . '<div>Date : <bdi>' . lt_e(date('d/m/Y', strtotime($date) ?: time())) . '</bdi></div>'
        . '</div>'];

    $subject = trim(preg_replace('/^subject\s*:\s*/iu', '', trim((string)($L['subject'] ?? ''))));
    $blocks[] = ['keep' => true, 'html' =>
        '<div class="ltm-subject" dir="ltr"><span class="ltm-subject-en">Subject : </span>'
        . '<span class="ltm-subject-' . (lt_dir($subject) === 'rtl' ? 'ku' : 'en') . '">' . lt_e($subject) . '</span></div>'];

    // ── The letter itself, in its own direction (Arabic/Kurdish or English) ──
    $dir = lt_dir((string)($L['body'] ?? '') . ' ' . (string)($L['recipient'] ?? ''));
    $box = function (string $class, string $html) use ($dir) {
        return '<div class="' . $class . ' ltm-' . $dir . '" dir="' . $dir . '">' . $html . '</div>';
    };
    $recipient = trim((string)($L['recipient'] ?? ''));
    if ($recipient !== '') {
        $blocks[] = ['keep' => true, 'html' => $box('ltm-to', lt_e($recipient))];
    }
    $greeting = trim((string)($L['greeting'] ?? ''));
    if ($greeting !== '') {
        $blocks[] = ['keep' => true, 'html' => $box('ltm-p', lt_e($greeting))];
    }
    foreach (lt_paragraphs((string)($L['body'] ?? ''), (string)($L['reference_text'] ?? ''), $mark) as $p) {
        $blocks[] = ['keep' => false, 'html' => $box('ltm-p', $p)];
    }
    $closing = trim((string)($L['closing'] ?? ''));
    if ($closing !== '') {
        $blocks[] = ['keep' => true, 'html' => $box('ltm-p', lt_e($closing))];
    }

    // ── Signer: name and details as lines, signature and seal beside them ──
    $name = trim((string)($L['signer_name'] ?? ''));
    $titleLines = lt_lines($L['signer_title'] ?? '');
    $signDir = lt_dir($name . ' ' . implode(' ', $titleLines));
    $lang = $signDir === 'rtl' ? 'ku' : 'en';
    $text = '<div class="ltm-sign-name-' . $lang . ' ltm-' . $signDir . '">' . ($name !== '' ? lt_e($name) : '&nbsp;') . '</div>';
    foreach ($titleLines as $line) {
        $text .= '<div class="ltm-sign-line-' . $lang . ' ltm-' . $signDir . '">' . lt_e($line) . '</div>';
    }
    $images = '';
    if (!empty($L['show_signature']) && !empty($img['signature'])) {
        $images .= '<div><img class="ltm-sign-img" src="' . lt_e($img['signature']) . '" alt=""></div>';
    }
    if (!empty($L['show_seal']) && !empty($img['seal'])) {
        $images .= '<div><img class="ltm-seal-img" src="' . lt_e($img['seal']) . '" alt=""></div>';
    }
    $blocks[] = ['keep' => true, 'html' =>
        '<table class="ltm-sign" dir="' . $signDir . '"><tr>'
        . '<td class="ltm-sign-text" align="' . ($signDir === 'rtl' ? 'right' : 'left') . '">' . $text . '</td>'
        . '<td class="ltm-sign-imgs" align="center">' . $images . '</td>'
        . '</tr></table>'];

    $att = lt_modern_list('هاوپێچ:', 'Attachments:', $L['attachments'] ?? '');
    $cc = lt_modern_list('وێنەیەک بۆ:', 'CC:', $L['cc_list'] ?? '');
    if ($att !== '' || $cc !== '') {
        $blocks[] = ['keep' => true, 'html' => $att . $cc];
    }
    return $blocks;
}

/** The modern design's styling; $forPdf swaps fonts mPDF can embolden. */
function lt_modern_css(array $cfg, bool $forPdf): string
{
    $c = $cfg['letter_modern_color'];
    $soft = lt_tint($c, 0.3);
    $ink = '#3a4450';
    $latin = $forPdf ? 'dejavusanscondensed' : "'Segoe UI', Arial, sans-serif";
    // One class per element and no «.a .b» selectors: mPDF ignores those and
    // does not pass fonts from a table cell down to the text inside it.
    return "
.ltm-head { width: 100%; border-collapse: collapse; }
.ltm-head-r, .ltm-head-l, .ltm-head-mid { vertical-align: middle; padding: 0; }
.ltm-head-r { width: 37%; text-align: right; }
.ltm-head-l { width: 37%; text-align: left; direction: ltr; }
.ltm-head-mid { width: 26%; text-align: center; }
.ltm-logo { height: 31mm; }
.ltm-h1-ku { font-size: 21pt; font-weight: bold; color: {$c}; line-height: 1.2; text-align: right; }
.ltm-h2-ku { font-size: 11pt; font-weight: bold; color: {$soft}; line-height: 1.5; text-align: right; }
.ltm-h1-en { font-family: {$latin}; font-size: 19pt; font-weight: bold; color: {$c}; letter-spacing: 0.4pt; line-height: 1.2; text-align: left; }
.ltm-h2-en { font-family: {$latin}; font-size: 9.5pt; font-weight: bold; color: {$soft}; letter-spacing: 1pt; line-height: 1.5; text-align: left; }
.ltm-bar { margin-top: 4mm; border-top: 1.1mm solid {$c}; height: 0; }
.ltm-meta { margin-top: 8mm; font-family: {$latin}; text-align: left; direction: ltr; font-size: 12.5pt; font-weight: bold; color: {$ink}; line-height: 1.6; }
.ltm-subject { margin-top: 6mm; text-align: center; font-size: 13pt; color: {$ink}; }
.ltm-subject-en { font-family: {$latin}; font-size: 13pt; font-weight: bold; color: {$ink}; }
.ltm-subject-ku { font-size: 13pt; font-weight: bold; color: {$ink}; }
.ltm-rtl { text-align: right; direction: rtl; }
.ltm-ltr { text-align: left; direction: ltr; }
.ltm-to { margin-top: 12mm; font-weight: bold; }
.ltm-p { margin-top: 2mm; line-height: 1.9; }
.ltm-sign { width: 100%; border-collapse: collapse; margin-top: 16mm; }
.ltm-sign-text { width: 60%; vertical-align: top; padding: 0; }
.ltm-sign-imgs { width: 40%; vertical-align: bottom; text-align: center; padding: 0; }
.ltm-sign-name-en, .ltm-sign-line-en { font-family: {$latin}; font-size: 12.5pt; font-weight: bold; color: {$ink}; line-height: 1.6; }
.ltm-sign-name-ku, .ltm-sign-line-ku { font-size: 13pt; font-weight: bold; color: {$ink}; line-height: 1.6; }
.ltm-sign-img { height: 20mm; max-width: 62mm; }
.ltm-seal-img { height: 23mm; }
.ltm-foot { width: 100%; border-collapse: collapse; }
.ltm-foot-r, .ltm-foot-l { width: 40%; vertical-align: middle; padding: 0; }
.ltm-foot-mid { width: 20%; text-align: center; vertical-align: middle; padding: 0; }
.ltm-pair { border-collapse: collapse; }
.ltm-pair-i { width: 9mm; vertical-align: middle; padding: 0; }
.ltm-pair-t { vertical-align: middle; padding: 0 2mm; }
.ltm-icon { width: 7.5mm; height: 7.5mm; }
.ltm-foot-tel { font-family: {$latin}; font-size: 11.5pt; font-weight: bold; line-height: 1.5; color: #ffffff; direction: ltr; text-align: left; }
.ltm-foot-addr { font-size: 9pt; line-height: 1.6; color: #ffffff; text-align: right; }
.ltm-qr { border-collapse: collapse; margin: 0 auto; }
.ltm-qr-cell { background: #ffffff; padding: 1.8mm; border-radius: 1.5mm; line-height: 0; }
.ltm-qr-img { width: 21mm; height: 21mm; }
" . ($forPdf
        ? ".ltm-h1-ku { text-outline: 0.09mm {$c}; }
.ltm-h2-ku { text-outline: 0.05mm {$soft}; }
.ltm-to, .ltm-subject-ku, .ltm-sign-name-ku, .ltm-sign-line-ku { text-outline: 0.07mm #111111; }
.ltm-foot-addr { text-outline: 0.03mm #ffffff; }
"
        : ".ltm-qr-img { display: block; }
");
}

/** Styling shared by the browser and mPDF — tables and blocks only, no flex. */
function lt_css(array $cfg, bool $forPdf): string
{
    $a = $cfg['letter_accent_color'];
    $font = $forPdf ? 'nrt' : "var(--app-font-family, 'NRT'), Tahoma, sans-serif";
    return "
.lt-root, .lt-root td { font-family: {$font}; color: #111; }
.lt-root { font-size: " . lt_pt($cfg) . "; line-height: 1.75; }
.lt-head { padding-bottom: 2.5mm; }
.lt-head-line { border-bottom: 0.6mm solid {$a}; }
.lt-head-tbl { width: 100%; border-collapse: collapse; }
.lt-head-tbl td { vertical-align: middle; padding: 0; }
.lt-head-side { width: 38%; }
.lt-head-r { text-align: right; }
.lt-head-l { text-align: left; direction: ltr; }
.lt-head-mid { width: 24%; text-align: center; }
.lt-head-t1 { font-size: " . lt_pt($cfg, 1.08) . "; font-weight: bold; color: {$a}; line-height: 1.5; }
.lt-head-t2 { font-size: " . lt_pt($cfg, 0.8) . "; color: #333; line-height: 1.5; }
.lt-logo { height: 24mm; }
.lt-head-mid-t { font-size: " . lt_pt($cfg, 0.72) . "; color: #444; line-height: 1.4; }
.lt-head-img { text-align: center; }
.lt-head-img img { width: 100%; }
.lt-meta { width: 100%; border-collapse: collapse; margin-top: 5mm; }
.lt-meta td { padding: 0; vertical-align: top; font-size: " . lt_pt($cfg, 0.92) . "; line-height: 1.6; }
.lt-meta-r { text-align: right; }
.lt-meta-l { text-align: left; }
.lt-label { font-weight: bold; }
.lt-to { margin-top: 8mm; font-weight: bold; font-size: " . lt_pt($cfg, 1.06) . "; }
.lt-subject { margin-top: 2.5mm; font-weight: bold; font-size: " . lt_pt($cfg, 1.06) . "; }
.lt-greet { margin-top: 7mm; font-weight: bold; }
.lt-p { margin-top: 3mm; text-align: justify; line-height: 1.95; }
.lt-sign { width: 100%; border-collapse: collapse; margin-top: 7mm; }
.lt-sign td { padding: 0; vertical-align: top; }
.lt-sign-pad { width: 52%; }
.lt-sign-pad2 { width: 26%; }
.lt-sign-cell { width: 48%; text-align: center; }
.lt-closing { font-weight: bold; margin-bottom: 1mm; }
.lt-sign-imgs { text-align: center; white-space: nowrap; }
.lt-sign-img { height: 19mm; max-width: 52mm; vertical-align: middle; }
.lt-seal-img { height: 25mm; vertical-align: middle; }
.lt-sign-name { font-weight: bold; }
.lt-sign-gap { margin-top: 20mm; }
.lt-sign-title { font-size: " . lt_pt($cfg, 0.94) . "; line-height: 1.6; }
.lt-list { margin-top: 5mm; font-size: " . lt_pt($cfg, 0.84) . "; line-height: 1.65; }
.lt-list-h { font-weight: bold; text-decoration: underline; }
mark { background: #fff1a8; color: inherit; }
" . ($forPdf
        // mPDF has no bold NRT and its artificial bold skips shaped Arabic-
        // script runs; an outline stroke in the text colour reads as bold.
        ? ".lt-head-t1 { text-outline: 0.07mm {$a}; }
.lt-label, .lt-to, .lt-subject, .lt-greet, .lt-closing, .lt-sign-name, .lt-list-h { text-outline: 0.07mm #111111; }
"
        // The stamp sits half over the signature, as it does on paper.
        : ".lt-seal-img { margin-inline-start: -9mm; }
") . lt_modern_css($cfg, $forPdf);
}

/**
 * The full browser page.
 *   $opts: title, toolbar (HTML above the sheet, never printed), notice
 *          (HTML under the toolbar, never printed), autoprint, preview,
 *          font_css (URL), head (extra <head> HTML),
 *          footer_ratio (height / width of the footer image)
 */
function lt_document(array $L, array $cfg, array $img, array $opts = []): string
{
    $opts['design'] = lt_design($L, $cfg);
    $isModern = $opts['design'] === 'modern';
    $space = lt_footer_space($cfg, $img, $opts);
    $rows = '';
    foreach (lt_blocks($L, $cfg, $img, $opts) as $b) {
        $rows .= '<tr' . ($b['keep'] ? ' class="lt-keep"' : '') . '><td>' . $b['html'] . '</td></tr>';
    }
    $footer = lt_footer_html($cfg, $img, $opts);
    $void = ($L['status'] ?? '') === 'cancelled' ? '<div class="lt-void">هەڵوەشێنراوەتەوە</div>' : '';
    $fontCss = (!empty($opts['font_css']) ? '<link rel="stylesheet" href="' . lt_e($opts['font_css']) . '">' : '')
        . ($opts['head'] ?? '');
    // Modern: the faded logo behind the text and the wave band under the
    // footer, both fixed in print so every page gets them.
    $modernLayers = '';
    if ($isModern) {
        if ($cfg['letter_modern_watermark'] === '1' && !empty($img['logo'])) {
            $modernLayers .= '<img class="ltm-wm" src="' . lt_e($img['logo']) . '" alt="">';
        }
        $modernLayers .= '<div class="ltm-bandbg" style="height:' . lt_modern_band_height($img) . 'mm">'
            . '<img src="' . lt_svg_uri(lt_modern_band_svg($cfg, $img)) . '" alt=""></div>';
    }

    return '<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>' . lt_e($opts['title'] ?? 'نوسراوی فەرمی') . '</title>
' . $fontCss . '
<style>
@page { size: A4; margin: 0; }
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; }
body { background: #e4e8ee; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.lt-sheet { position: relative; width: 210mm; min-height: 297mm; margin: 18px auto; padding: 0 18mm; background: #fff; box-shadow: 0 2px 16px rgba(15, 23, 42, .16); }
.lt-frame { width: 100%; border-collapse: collapse; }
.lt-frame > thead > tr > td, .lt-frame > tfoot > tr > td, .lt-frame > tbody > tr > td { padding: 0; }
.lt-space-top { height: 14mm; }
.lt-space-bottom { height: ' . round($space, 1) . 'mm; }
.lt-keep { break-inside: avoid; page-break-inside: avoid; }
.lt-footer { position: absolute; left: 18mm; right: 18mm; bottom: 8mm; }
.lt-void { position: absolute; top: 40%; left: 0; right: 0; text-align: center; font-size: 60pt; font-weight: bold; color: rgba(198, 40, 40, .14); transform: rotate(-28deg); pointer-events: none; }
.lt-toolbar { position: sticky; top: 0; z-index: 10; display: flex; flex-wrap: wrap; gap: 8px; justify-content: center; align-items: center; padding: 10px 12px; background: #1f2937; font-family: var(--app-font-family, Tahoma), sans-serif; }
.lt-toolbar a, .lt-toolbar button { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border: 0; border-radius: 6px; background: #374151; color: #fff; font: inherit; font-size: 14px; text-decoration: none; cursor: pointer; }
.lt-toolbar .lt-primary { background: #2563eb; }
.lt-toolbar .lt-pdf { background: #b91c1c; }
.lt-toolbar a:hover, .lt-toolbar button:hover { filter: brightness(1.12); }
.lt-notice { max-width: 210mm; margin: 12px auto 0; padding: 10px 14px; border-radius: 6px; background: #fef2f2; color: #991b1b; font-family: var(--app-font-family, Tahoma), sans-serif; font-size: 14px; }
.lt-notice.is-ok { background: #ecfdf5; color: #065f46; }
body.lt-is-preview { background: #d9dee6; }
body.lt-is-preview .lt-sheet { margin: 0 auto; }
.lt-frame { position: relative; z-index: 1; }
.lt-footer { z-index: 2; }
.ltm-sheet .lt-footer { bottom: 5mm; }
.ltm-wm { position: absolute; top: 50%; left: 50%; width: 112mm; transform: translate(-50%, -50%); opacity: .07; mix-blend-mode: multiply; pointer-events: none; z-index: 0; }
.ltm-bandbg { position: absolute; left: 0; right: 0; bottom: 0; z-index: 0; line-height: 0; }
.ltm-bandbg img { width: 100%; height: 100%; display: block; }
' . lt_css($cfg, false) . '
@media print {
    html, body, body.lt-is-preview { background: #fff !important; }
    .lt-toolbar, .lt-notice { display: none !important; }
    .lt-sheet { margin: 0; box-shadow: none; min-height: 0; }
    .lt-footer, .lt-void, .ltm-wm, .ltm-bandbg { position: fixed; }
    mark { background: none; }
}
</style>
</head>
<body class="' . (!empty($opts['preview']) ? 'lt-is-preview' : '') . '">
' . ($opts['toolbar'] ?? '') . ($opts['notice'] ?? '') . '
<div class="lt-sheet lt-root' . ($isModern ? ' ltm-sheet' : '') . '">
' . $modernLayers . '
<table class="lt-frame">
<thead><tr><td><div class="lt-space-top"></div></td></tr></thead>
<tfoot><tr><td><div class="lt-space-bottom"></div></td></tr></tfoot>
<tbody>' . $rows . '</tbody>
</table>
' . ($footer !== '' ? '<div class="lt-footer">' . $footer . '</div>' : '') . '
' . $void . '
</div>
' . (!empty($opts['autoprint']) ? '<script>window.addEventListener("load", function () { setTimeout(function () { window.print(); }, 300); });</script>' : '') . '
</body>
</html>';
}

/**
 * The same letter as a real PDF (mPDF). Returns the Mpdf object; the caller
 * decides how to output it.
 *   $opts: footer_ratio, font_dir (folder holding NRT-Reg.ttf), temp_dir
 */
function lt_pdf(array $L, array $cfg, array $img, array $opts = []): \Mpdf\Mpdf
{
    $defaultFont = (new \Mpdf\Config\FontVariables())->getDefaults();
    $defaultConfig = (new \Mpdf\Config\ConfigVariables())->getDefaults();
    $tempDir = $opts['temp_dir'] ?? (sys_get_temp_dir() . '/mpdf');
    if (!is_dir($tempDir)) {
        @mkdir($tempDir, 0775, true);
    }

    $opts['design'] = lt_design($L, $cfg);
    $isModern = $opts['design'] === 'modern';
    $mpdf = new \Mpdf\Mpdf([
        'mode'              => 'utf-8',
        'format'            => 'A4',
        'default_font'      => 'nrt',
        'default_font_size' => (int)$cfg['letter_font_size'] * 0.75,
        'fontDir'           => array_merge($defaultConfig['fontDir'], [$opts['font_dir']]),
        'fontdata'          => $defaultFont['fontdata'] + [
            'nrt' => ['R' => 'NRT-Reg.ttf', 'useOTL' => 0xFF, 'useKashida' => 75],
        ],
        'margin_left'       => 18,
        'margin_right'      => 18,
        'margin_top'        => 14,
        'margin_bottom'     => lt_footer_space($cfg, $img, $opts),
        'margin_header'     => 0,
        'margin_footer'     => $isModern ? 5 : 8,
        'tempDir'           => $tempDir,
        'autoLangToFont'    => true,
        'autoScriptToLang'  => true,
    ]);
    $mpdf->SetDirectionality('rtl');
    $mpdf->SetTitle('نوسراوی ژمارە ' . ($L['letter_number'] ?? ''));
    $mpdf->SetCreator(defined('APP_NAME') ? APP_NAME : 'Official letters');

    $footer = lt_footer_html($cfg, $img, $opts);
    $footerTag = '';
    if ($footer !== '' && $isModern) {
        // A page with its own @page background drops a SetHTMLFooter()
        // footer; one named in that @page rule is kept (see $pageCss).
        $footerTag = '<htmlpagefooter name="ltmFoot"><div dir="rtl" style="font-family:nrt">' . $footer . '</div></htmlpagefooter>';
    } elseif ($footer !== '') {
        $mpdf->SetHTMLFooter('<div dir="rtl" style="font-family:nrt">' . $footer . '</div>');
    }
    if (($L['status'] ?? '') === 'cancelled') {
        $mpdf->SetWatermarkText('هەڵوەشێنراوەتەوە', 0.1);
        $mpdf->watermark_font = 'nrt';
        $mpdf->showWatermarkText = true;
    }

    $pageCss = '';
    if ($isModern) {
        // The wave band is the page background (full bleed, every page); the
        // phones / QR / address are the HTML footer drawn on top of it.
        $pageCss = '@page { background-image: url("' . lt_svg_uri(lt_modern_band_svg($cfg, $img)) . '");'
            . ' background-repeat: no-repeat; background-position: center bottom;'
            . ($footerTag !== '' ? ' footer: html_ltmFoot;' : '') . ' }';
        if ($cfg['letter_modern_watermark'] === '1' && !empty($img['logo'])) {
            $w = 112;
            $mpdf->SetWatermarkImage($img['logo'], 0.07, [$w, round($w * (lt_image_ratio($img['logo']) ?: 1), 1)], 'P');
            $mpdf->showWatermarkImage = true;
            $mpdf->watermarkImgBehind = true;
        }
    }

    $html = '';
    foreach (lt_blocks($L, $cfg, $img, $opts) as $b) {
        $html .= $b['keep'] ? '<div style="page-break-inside: avoid">' . $b['html'] . '</div>' : $b['html'];
    }
    $mpdf->WriteHTML($pageCss . lt_css($cfg, true), \Mpdf\HTMLParserMode::HEADER_CSS);
    $mpdf->WriteHTML($footerTag . '<div class="lt-root" dir="rtl">' . $html . '</div>', \Mpdf\HTMLParserMode::HTML_BODY);
    return $mpdf;
}

/** height / width of an image file (0 when unreadable) — sizes the footer image. */
function lt_image_ratio(?string $path): float
{
    if (!$path || !is_file($path)) {
        return 0.0;
    }
    $info = @getimagesize($path);
    return ($info && $info[0] > 0) ? $info[1] / $info[0] : 0.0;
}
