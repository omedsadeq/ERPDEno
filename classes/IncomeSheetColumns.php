<?php
/**
 * داهات — the income sheet's own columns: a name and «پیشاندان / شاردنەوە»
 * for each, set once for everyone in the dialog on accountant/income.php and
 * kept in settings (income_sheet_columns, JSON). The screen and the PDF read
 * the same choice, so the printout is always exactly the screen.
 *
 * Only what is drawn changes: a hidden column still counts in every figure
 * beside it (کۆی ماوە, the two shares, the boxes under the table).
 */

require_once __DIR__ . '/Settings.php';

class IncomeSheetColumns
{
    public const SETTING = 'income_sheet_columns';
    public const LABEL_MAX = 40;

    /** key => [default name, section]. The store's group heading is 'store'; its own total is 'storeTotal'. */
    public const COLUMNS = [
        'amount'          => ['بڕی', 'main'],
        'general'         => ['خەرجی گشتی', 'expense'],
        'lab'             => ['خەرجی تاقیگە', 'expense'],
        'store'           => ['خەرجی کۆگا', 'store'],
        'storeTotal'      => ['کۆ', 'store'],
        'storeNormal'     => ['ئاسایی', 'store'],
        'storeNormalDoc'  => ['سەر دکتۆر', 'store'],
        'storeNormalCtr'  => ['سەر لێزان', 'store'],
        'storeImplant'    => ['Implant', 'store'],
        'storeImplantDoc' => ['سەر دکتۆر', 'store'],
        'storeImplantCtr' => ['لێزان', 'store'],
        'pool'            => ['کۆی ماوە دوای خەرجی', 'result'],
        'doctorShare'     => ['پشکی دکتۆر', 'result'],
        'centerShare'     => ['پشکی سەنتەر', 'result'],
    ];

    /** The store's columns under its heading, in order: key => the row field and the cell's colour class. */
    public const STORE_PARTS = [
        'storeTotal'      => ['store', ''],
        'storeNormal'     => ['storeNormal', 'nrm'],
        'storeNormalDoc'  => ['storeNormalDoc', 'nrm'],
        'storeNormalCtr'  => ['storeNormalCtr', 'nrm'],
        'storeImplant'    => ['storeImplant', 'imp'],
        'storeImplantDoc' => ['storeImplantDoc', 'imp'],
        'storeImplantCtr' => ['storeImplantCtr', 'imp'],
    ];

    /** The heading has no cells of its own: it goes when all its columns are hidden. */
    public const NOT_HIDEABLE = ['store'];

    private static ?array $all = null;

    /** key => ['label', 'default', 'hidden', 'section'] */
    public static function all(): array
    {
        if (self::$all !== null) {
            return self::$all;
        }
        $saved = json_decode((string)Settings::get(self::SETTING, ''), true);
        $saved = is_array($saved) ? $saved : [];
        $out = [];
        foreach (self::COLUMNS as $key => [$default, $section]) {
            $label = trim((string)($saved[$key]['label'] ?? ''));
            $out[$key] = [
                'label'   => $label !== '' ? $label : $default,
                'default' => $default,
                'hidden'  => !in_array($key, self::NOT_HIDEABLE, true) && !empty($saved[$key]['hidden']),
                'section' => $section,
            ];
        }
        return self::$all = $out;
    }

    public static function label(string $key): string
    {
        return self::all()[$key]['label'] ?? $key;
    }

    public static function shown(string $key): bool
    {
        return !(self::all()[$key]['hidden'] ?? false);
    }

    /** The store's columns that are shown: key => [row field, colour class]. */
    public static function storeParts(): array
    {
        return array_filter(self::STORE_PARTS, fn($key) => self::shown($key), ARRAY_FILTER_USE_KEY);
    }

    /** The expense groups of $colSel that get columns: a hidden one drops out, the store while any part shows. */
    public static function expenseKeys(array $colSel): array
    {
        return array_values(array_filter($colSel, fn($k) => $k === 'store' ? (bool)self::storeParts() : self::shown($k)));
    }

    /**
     * Saves the dialog: label[key] (empty = the name it came with) and
     * shown[key] (a ticked box). Only a change from the default is kept.
     */
    public static function save(array $labels, array $shown): void
    {
        $keep = [];
        foreach (self::COLUMNS as $key => [$default]) {
            $label = trim(preg_replace('/\s+/u', ' ', (string)($labels[$key] ?? '')));
            $label = mb_substr($label, 0, self::LABEL_MAX);
            $entry = [];
            if ($label !== '' && $label !== $default) {
                $entry['label'] = $label;
            }
            if (!in_array($key, self::NOT_HIDEABLE, true) && empty($shown[$key])) {
                $entry['hidden'] = true;
            }
            if ($entry) {
                $keep[$key] = $entry;
            }
        }
        Settings::set(self::SETTING, json_encode($keep, JSON_UNESCAPED_UNICODE), 'json', 'ناو و پیشاندانی کۆڵۆمەکانی خشتەی داهات');
        Settings::invalidate();
        self::$all = null;
    }

    /** Back to the names the sheet came with, everything shown. */
    public static function reset(): void
    {
        Settings::set(self::SETTING, '{}', 'json');
        Settings::invalidate();
        self::$all = null;
    }
}
