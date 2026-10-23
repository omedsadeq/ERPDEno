<?php
/**
 * One letter on its A4 sheet, ready for the printer. The toolbar above it
 * (print / PDF / edit / archive) never prints. ?print=1 opens the print
 * dialog straight away — what «پاشەکەوتکردن و چاپکردن» lands on.
 */

require_once __DIR__ . '/_bootstrap.php';
letters_require('letters.view');

$id = (int)($_GET['id'] ?? 0);
$L = $id > 0 ? $letters->find($id) : null;
if (!$L) {
    header('Location: ' . APP_URL . '/letters/index.php?missing=1');
    exit;
}
$cfg = $letters->settings();
$canEdit = $perm->can('letters.create');
$digits = $cfg['letter_digits'];

$e = 'lt_e';
$toolbar = '<div class="lt-toolbar">'
    . '<a href="' . $e(APP_URL . '/letters/index.php') . '"><i class="fas fa-arrow-right"></i> ئەرشیف</a>'
    . '<button type="button" class="lt-primary" onclick="window.print()"><i class="fas fa-print"></i> چاپکردن</button>'
    . '<a class="lt-pdf" href="' . $e(APP_URL . '/letters/pdf.php?id=' . $id) . '" target="_blank" rel="noopener"><i class="fas fa-file-pdf"></i> PDF</a>'
    . '<a href="' . $e(APP_URL . '/letters/pdf.php?id=' . $id . '&dl=1') . '"><i class="fas fa-download"></i> داگرتنی PDF</a>'
    . ($canEdit
        ? '<a href="' . $e(APP_URL . '/letters/edit.php?id=' . $id) . '"><i class="fas fa-pen"></i> دەستکاری</a>'
          . '<a href="' . $e(APP_URL . '/letters/edit.php?copy=' . $id) . '"><i class="fas fa-clone"></i> کۆپی بۆ نوسراوی نوێ</a>'
          . '<a href="' . $e(APP_URL . '/letters/edit.php') . '"><i class="fas fa-plus"></i> نوسراوی نوێ</a>'
        : '')
    . '</div>';

$notice = '';
if ($L['status'] === 'cancelled') {
    $notice = '<div class="lt-notice"><b>ئەم نوسراوە هەڵوەشێنراوەتەوە</b>'
        . ($L['cancel_reason'] ? ' — ' . $e($L['cancel_reason']) : '')
        . ($L['cancelled_by_name'] ? ' (' . $e($L['cancelled_by_name']) . '، ' . $e(substr((string)$L['cancelled_at'], 0, 16)) . ')' : '')
        . '</div>';
} elseif (!empty($_GET['saved'])) {
    $notice = '<div class="lt-notice is-ok">نوسراوەکە پاشەکەوت کرا بە ژمارەی <b><bdi>' . $e(lt_digits($L['letter_number'], $digits)) . '</bdi></b></div>';
}

echo lt_document($L, $cfg, OfficialLetter::assetUrls($cfg), [
    'title'     => 'نوسراوی ژمارە ' . $L['letter_number'] . ' — ' . $L['subject'],
    'toolbar'   => $toolbar,
    'notice'    => $notice,
    'autoprint' => !empty($_GET['print']),
    'font_css'  => asset_url('assets/css/fonts.css'),
    'head'      => '<link rel="stylesheet" href="' . $e(APP_URL . '/assets/vendor/fontawesome/css/all.min.css') . '">',
] + letters_render_opts($cfg));
