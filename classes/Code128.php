<?php
/**
 * Code128 — a Code 128 barcode as inline SVG, for the prescription sheet
 * (erp2/prescription-print.php), so a counter scanner can read it back.
 *
 * An even run of digits is encoded as Code 128C (two digits a symbol — half
 * the width), anything else as Code 128B (printable ASCII). No library: the
 * symbol table below is the standard one, each symbol 11 modules wide (bar,
 * space, bar, space, bar, space), the stop 13.
 */
class Code128
{
    const START_B = 104;
    const START_C = 105;
    const STOP    = 106;

    const PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    /** Symbol values (start, data, checksum, stop) for $data. */
    public static function symbols(string $data): array
    {
        if ($data === '' || preg_match('/[^\x20-\x7E]/', $data)) {
            throw new InvalidArgumentException('Code 128: printable ASCII only');
        }
        if (preg_match('/^(\d\d)+$/', $data)) {
            $codes = [self::START_C];
            foreach (str_split($data, 2) as $pair) {
                $codes[] = (int)$pair;
            }
        } else {
            $codes = [self::START_B];
            foreach (str_split($data) as $ch) {
                $codes[] = ord($ch) - 32;
            }
        }
        $sum = $codes[0];
        for ($i = 1, $n = count($codes); $i < $n; $i++) {
            $sum += $codes[$i] * $i;
        }
        $codes[] = $sum % 103;
        $codes[] = self::STOP;
        return $codes;
    }

    /**
     * The barcode as an SVG element, $moduleMm per narrow bar and $heightMm
     * tall, with the 10-module quiet zone each side built in.
     */
    public static function svg(string $data, float $moduleMm = 0.33, float $heightMm = 9.0): string
    {
        $x = 10;   // quiet zone, in modules
        $rects = '';
        foreach (self::symbols($data) as $code) {
            $bar = true;
            foreach (str_split(self::PATTERNS[$code]) as $w) {
                $w = (int)$w;
                if ($bar) {
                    $rects .= '<rect x="' . $x . '" y="0" width="' . $w . '" height="1"/>';
                }
                $x += $w;
                $bar = !$bar;
            }
        }
        $total = $x + 10;
        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" class="c128" viewBox="0 0 %d 1" preserveAspectRatio="none" '
            . 'width="%.2fmm" height="%.2fmm" shape-rendering="crispEdges" role="img" aria-label="%s">%s</svg>',
            $total, $total * $moduleMm, $heightMm, htmlspecialchars($data, ENT_QUOTES, 'UTF-8'), $rects
        );
    }
}
