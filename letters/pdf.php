<?php
/**
 * One letter as a real PDF file (mPDF), for e-mailing or keeping. Opens in
 * the browser; ?dl=1 downloads it instead.
 */

require_once __DIR__ . '/_bootstrap.php';
letters_require('letters.view');

$id = (int)($_GET['id'] ?? 0);
$L = $id > 0 ? $letters->find($id) : null;
if (!$L) {
    http_response_code(404);
    exit('نوسراو نەدۆزرایەوە');
}
$cfg = $letters->settings();

require_once APP_ROOT . '/vendor/autoload.php';
$mpdf = lt_pdf($L, $cfg, OfficialLetter::assetPaths($cfg), [
    'font_dir' => APP_ROOT . '/fonts/NRT Font',
] + letters_render_opts($cfg));

$name = preg_replace('/[^0-9A-Za-z_-]+/', '-', OfficialLetter::normalizeDigits($L['letter_number']));
$filename = 'letter-' . trim($name, '-') . '-' . $L['letter_date'] . '.pdf';

// config.php's gzip buffer must not wrap a binary download.
while (ob_get_level() > 0) {
    ob_end_clean();
}
$mpdf->Output($filename, !empty($_GET['dl']) ? \Mpdf\Output\Destination::DOWNLOAD : \Mpdf\Output\Destination::INLINE);
