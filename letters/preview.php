<?php
/**
 * Live preview for letters/edit.php and letters/settings.php: draws the
 * posted, unsaved letter (or a sample one) on the A4 sheet. Writes nothing.
 *
 *   POST letter fields          → that letter with the saved letterhead
 *   POST sample=1 + cfg[...]    → a sample letter with the unsaved letterhead
 *                                 (cfg is honoured only for letters.settings)
 */

require_once __DIR__ . '/_bootstrap.php';
letters_require('letters.create', 'letters.settings');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}
Csrf::require();

$cfg = $letters->settings();
if (!empty($_POST['cfg']) && is_array($_POST['cfg']) && $perm->can('letters.settings')) {
    foreach ($_POST['cfg'] as $key => $value) {
        // Image file names only change through an upload on save.
        if (array_key_exists($key, $cfg) && !in_array($key, OfficialLetter::ASSETS, true) && is_string($value)) {
            $cfg[$key] = $value;
        }
    }
    $cfg = OfficialLetter::sanitizeSettings($cfg);
}

$field = function (string $key, string $placeholder = '') {
    $v = trim(str_replace(["\r\n", "\r"], "\n", (string)($_POST[$key] ?? '')));
    return $v !== '' ? $v : $placeholder;
};

if (!empty($_POST['sample'])) {
    $date = date('Y-m-d');
    $L = [
        'letter_number'  => OfficialLetter::formatNumber($cfg['letter_number_format'], 12, $date),
        'letter_date'    => $date,
        'recipient'      => 'بەڕێوەبەرایەتی باجی دەرامەت',
        'subject'        => 'داواکاری لێخۆشبوون لە غەرامە',
        'greeting'       => $cfg['letter_default_greeting'],
        'body'           => "بەڕێزتان ئاگادار دەکەینەوە کە سەنتەرەکەمان بەهۆی دواکەوتنی ناردنی بەڵگەنامەکان نەیتوانیوە لە کاتی دیاریکراودا باجی ساڵانە جێبەجێ بکات، بۆیە داواکارین بە چاوی ڕەزامەندییەوە سەیری داواکارییەکەمان بکەن و لە غەرامەی دواکەوتن لێمان خۆش بن.\n\nهیوادارین داواکارییەکەمان جێگەی ڕەزامەندی بەڕێزتان بێت.",
        'closing'        => $cfg['letter_default_closing'],
        'signer_name'    => $cfg['letter_default_signer_name'] !== '' ? $cfg['letter_default_signer_name'] : 'ناوی واژۆکەر',
        'signer_title'   => $cfg['letter_default_signer_title'] !== '' ? $cfg['letter_default_signer_title'] : 'ناونیشانی وەزیفی',
        'cc_list'        => "بەشی یاسایی / بۆ ئاگاداری\nدۆسیەی تایبەت",
        'show_signature' => 1,
        'show_seal'      => 1,
    ];
} else {
    $date = OfficialLetter::normalizeDigits($field('letter_date', date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
        $date = date('Y-m-d');
    }
    $L = [
        'letter_number'  => $field('letter_number'),
        'letter_date'    => $date,
        'recipient'      => $field('recipient', '..........'),
        'subject'        => $field('subject', '..........'),
        'reference_text' => $field('reference_text'),
        'greeting'       => $field('greeting'),
        'body'           => $field('body', '........................................'),
        'closing'        => $field('closing'),
        'signer_name'    => $field('signer_name'),
        'signer_title'   => $field('signer_title'),
        'cc_list'        => $field('cc_list'),
        'attachments'    => $field('attachments'),
        'show_signature' => !empty($_POST['show_signature']),
        'show_seal'      => !empty($_POST['show_seal']),
    ];
}
$L['status'] = 'active';

header('Content-Type: text/html; charset=utf-8');
echo lt_document($L, $cfg, OfficialLetter::assetUrls($cfg), [
    'title'    => 'پێشبینین',
    'preview'  => true,
    'font_css' => asset_url('assets/css/fonts.css'),
] + letters_render_opts($cfg));
