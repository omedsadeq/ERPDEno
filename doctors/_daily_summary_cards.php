<?php
/**
 * پوختەی ڕۆژانە «مۆدێرن» — the cards themselves: which icon and colour each
 * figure gets, and the row lists (ds_row_lists) turned into cards. Shared by
 * the page's modern look (_daily_summary_modern.php) and its modern PDF
 * (_daily_summary_pdf_modern.php), so both always show the same cards.
 */

/** Font Awesome 6 (solid) — the class the page uses → the glyph the PDF draws. */
const DSM_GLYPHS = [
    'fa-coins' => 0xf51e, 'fa-minus-circle' => 0xf056, 'fa-wallet' => 0xf555, 'fa-hand-holding-usd' => 0xf4c0,
    'fa-money-bill-wave' => 0xf53a, 'fa-hourglass-half' => 0xf252, 'fa-undo-alt' => 0xf2ea, 'fa-cash-register' => 0xf788,
    'fa-flask' => 0xf0c3, 'fa-dolly' => 0xf472, 'fa-receipt' => 0xf543, 'fa-database' => 0xf1c0, 'fa-hospital' => 0xf0f8,
    'fa-users' => 0xf0c0, 'fa-briefcase-medical' => 0xf469, 'fa-stethoscope' => 0xf0f1, 'fa-file-medical' => 0xf477,
    'fa-random' => 0xf074, 'fa-user-plus' => 0xf234, 'fa-history' => 0xf1da, 'fa-x-ray' => 0xf497,
    'fa-clipboard-list' => 0xf46d, 'fa-clock' => 0xf017, 'fa-calendar-check' => 0xf274, 'fa-business-time' => 0xf64a,
    'fa-couch' => 0xf4b8, 'fa-user-md' => 0xf0f0, 'fa-check-circle' => 0xf058, 'fa-piggy-bank' => 0xf4d3,
    'fa-circle' => 0xf111, 'fa-tooth' => 0xf5c9, 'fa-calendar-alt' => 0xf073, 'fa-user-injured' => 0xf728,
    'fa-list-alt' => 0xf03a, 'fa-stopwatch' => 0xf2f2, 'fa-folder-open' => 0xf07c,
];

/** The colours: name => [line / icon, tint]. */
const DSM_COLOURS = [
    'purple' => ['#8b5cf6', '#f3e8ff'], 'pink' => ['#ec4899', '#fce7f3'], 'blue' => ['#3b82f6', '#dbeafe'],
    'green' => ['#22c55e', '#dcfce7'], 'orange' => ['#f59e0b', '#fef3c7'], 'red' => ['#ef4444', '#fee2e2'],
    'teal' => ['#14b8a6', '#ccfbf1'], 'indigo' => ['#6366f1', '#e0e7ff'], 'slate' => ['#64748b', '#f1f5f9'],
];

/** A card's icon and colour, by its label (a label not listed gets the plain one). */
function dsm_look(string $label): array
{
    static $map = [
        'کۆی گشتی پارە'           => ['fa-coins', 'purple'],
        'بڕی داشکێنراو'           => ['fa-minus-circle', 'pink'],
        'ماوەی دوای داشکێنراو'    => ['fa-wallet', 'blue'],
        'بڕی ماوە'                => ['fa-hand-holding-usd', 'orange'],
        'بڕی وەرگیراو'            => ['fa-money-bill-wave', 'green'],
        'وەرگیراوی چاوەڕوانی پەسەندکردن' => ['fa-hourglass-half', 'orange'],
        'پارەی گەڕاوەی قەرزی کۆن' => ['fa-undo-alt', 'teal'],
        'کۆی نەقدی وەرگیراو'      => ['fa-cash-register', 'green'],
        'خەرجی تاقیگە'            => ['fa-flask', 'red'],
        'خەرجی کۆگا'              => ['fa-dolly', 'teal'],
        'خەرجی گشتی'              => ['fa-receipt', 'red'],
        'ماوەی دوای خەرجی'        => ['fa-database', 'purple'],
        'پشکی سەنتەر'             => ['fa-hospital', 'blue'],
        'کۆی گشتی سەردانیکەران'   => ['fa-users', 'indigo'],
        'کۆی گشتی ئیش'            => ['fa-briefcase-medical', 'green'],
        'کۆی گشتی پشکنین'         => ['fa-stethoscope', 'blue'],
        'پشکنینی داواکراو'        => ['fa-file-medical', 'pink'],
        'پشکنینی دیاریکردنی تر'   => ['fa-random', 'slate'],
        'پشکنینی نوێ'             => ['fa-user-plus', 'purple'],
        'پشکنینی کۆن'             => ['fa-history', 'orange'],
        'نەخۆشی نێردراو'          => ['fa-x-ray', 'indigo'],
        'کۆی داواکاری'            => ['fa-clipboard-list', 'slate'],
        'کاتژمێری هاتن'           => ['fa-clock', 'blue'],
        'ڕۆژی هاتن'               => ['fa-calendar-check', 'purple'],
        'کۆی کاتی ئیشکردن'        => ['fa-business-time', 'purple'],
        'کۆی کاتی پشکنین'         => ['fa-stethoscope', 'teal'],
        'کۆی کاتی بەتال'          => ['fa-couch', 'orange'],
    ];
    if (isset($map[$label])) {
        return $map[$label];
    }
    if (strpos($label, 'پشکی دکتۆر') === 0) {
        return ['fa-user-md', 'green'];
    }
    if (strpos($label, 'پشکنینی پێدراو') === 0) {
        return ['fa-check-circle', 'teal'];
    }
    if (strpos($label, 'ئامانەتی وەرگیراو') === 0) {
        return ['fa-piggy-bank', 'slate'];
    }
    return ['fa-circle', 'slate'];
}

/**
 * One list of rows as cards: [label, value, neg, note, notes[], total, icon, colour].
 * A «— …» row (the store split into ئاسایی / Implant) is a note on the card
 * before it, not a card of its own. An expense is printed «(1,000)» in the
 * tables; on a card it is just the amount, as the label already says it is
 * spent. Any other «(…)» is a loss and shows as a minus.
 */
function dsm_card_list(array $rows): array
{
    $cards = [];
    foreach ($rows as [$label, $value, $style]) {
        if (strpos($label, '— ') === 0 && $cards) {
            $cards[count($cards) - 1]['notes'][] = substr($label, strlen('— '));
            continue;
        }
        $neg = false;
        if (preg_match('/^\((.*)\)$/u', $value, $m)) {
            $value = $m[1];
            $neg = $style !== 'neg';
        }
        // a long label's «(…)» goes under it, smaller
        $note = '';
        if (preg_match('/^(.+?) \((.+)\)$/u', $label, $m) && mb_strlen($m[2]) > 6) {
            [$label, $note] = [$m[1], $m[2]];
        }
        [$icon, $colour] = dsm_look($label);
        $cards[] = ['label' => $label, 'value' => ($neg ? '-' : '') . $value, 'neg' => $neg,
                    'note' => $note, 'notes' => [], 'total' => $style === 'total', 'icon' => $icon, 'colour' => $colour];
    }
    return $cards;
}
