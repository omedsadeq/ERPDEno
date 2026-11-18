<?php
/**
 * فۆرمولی تایبەتی پشکی دکتۆر — a doctor's own way of working out his share.
 *
 * Most doctors are split the owner's sheet's way (ds_doctor_share in
 * doctors/_daily_summary_lib.php). A doctor whose deal is different — the
 * store taken off before his percentage, half of it, nothing of it … — gets a
 * formula of his own, written like an Excel cell on the income sheet
 * (accountant/income.php, the «fx» beside his name) and kept in the settings
 * (income_formula_d<users.id>). While one is set, it REPLACES the sheet's rule
 * for him everywhere the share is worked out (ds_doctor_share_of): the income
 * sheet, his daily summary, دابەشکردنی داهات. The centre's share is still the
 * net less his.
 *
 * The language: numbers, the names below (Kurdish aliases too), + − × ÷,
 * brackets, a trailing % (rate% = rate ÷ 100, 20% = 0.2) and min / max / abs /
 * round. It is read by this class alone — never by PHP's eval.
 *
 *   (amount - general - lab - normal / 2 - implant) * rate%
 */

require_once __DIR__ . '/Settings.php';

final class IncomeFormula
{
    public const SETTING = 'income_formula_d';

    /** The owner's sheet (ds_doctor_share) in this language — the dialog's example. */
    public const DEFAULT_TEXT = '(amount - general - lab - columns + on_doctor) * rate% - normal * normal_rate% - implant * implant_rate% - on_doctor';

    private const FUNCS = ['min' => [2, 99], 'max' => [2, 99], 'abs' => [1, 1], 'round' => [1, 2]];

    /** The names a formula may use: name => [label, aliases]. */
    public static function vars(): array
    {
        return [
            'amount'       => ['بڕی (کۆی گشتی)',                    ['بڕ', 'بڕی', 'کۆ']],
            'general'      => ['خەرجی گشتی',                         ['گشتی']],
            'lab'          => ['خەرجی تاقیگە',                       ['تاقیگە', 'موختەبەر']],
            'normal'       => ['کۆگای ئاسایی',                       ['ئاسایی']],
            'implant'      => ['کۆگای Implant',                      ['ئیمپلانت']],
            'store'        => ['کۆگا (ئاسایی + Implant)',             ['کۆگا']],
            'columns'      => ['کۆڵۆمی تایبەت (هەمووی)',              ['کۆڵۆم', 'ستوون']],
            'on_doctor'    => ['لەوانە «هەمووی لەسەر دکتۆر»',        ['لەسەر_دکتۆر']],
            'net'          => ['سافی (بڕی − هەموو خەرجی − کۆڵۆم)',   ['سافی']],
            'rate'         => ['ڕێژەی دکتۆر %',                      ['ڕێژە']],
            'normal_rate'  => ['ڕێژەی ئاسایی %',                     ['ڕێژەی_ئاسایی']],
            'implant_rate' => ['ڕێژەی Implant %',                    ['ڕێژەی_ئیمپلانت']],
        ];
    }

    /** The doctor's formula, or null when he is split the sheet's way. */
    public static function forDoctor(int $doctorId): ?string
    {
        if (!defined('DB_HOST')) {
            return null;
        }
        $f = trim((string)Settings::get(self::SETTING . $doctorId, ''));
        return $f === '' ? null : $f;
    }

    /** Keep a doctor's formula ('' takes it away). Checked first; the error is thrown. */
    public static function save(int $doctorId, string $formula): void
    {
        $formula = trim(self::normalize($formula));
        if ($formula !== '' && ($err = self::check($formula)) !== null) {
            throw new InvalidArgumentException($err);
        }
        $key = self::SETTING . $doctorId;
        if ($formula === '') {
            Database::getInstance()->query('DELETE FROM settings WHERE setting_key = :k', ['k' => $key]);
            Settings::invalidate();
            return;
        }
        Settings::set($key, $formula, 'text', 'داهات: فۆرمولی تایبەتی پشکی ئەم دکتۆرە (لە جیاتی ڕێژە و دابەشکردنی کۆگا)');
    }

    /** Why a formula cannot be used, or null when it reads fine. */
    public static function check(string $formula): ?string
    {
        try {
            self::parse($formula);
            return null;
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }
    }

    /**
     * The formula's value for one set of figures (the names of vars(), a
     * missing one is 0). Throws InvalidArgumentException when it cannot be
     * read or worked out (a division by zero).
     */
    public static function evaluate(string $formula, array $vars): float
    {
        $v = [];
        foreach (array_keys(self::vars()) as $name) {
            $v[$name] = (float)($vars[$name] ?? 0);
        }
        $out = self::run(self::parse($formula), $v);
        if (!is_finite($out)) {
            throw new InvalidArgumentException('ئەنجامی فۆرمولەکە ژمارە نییە');
        }
        return $out;
    }

    // ── reading ──────────────────────────────────────────────────────

    /** Kurdish digits and signs as typed → the plain ones the reader knows. */
    public static function normalize(string $s): string
    {
        $s = str_replace(["\u{200C}", "\u{200F}", "\u{200E}"], '', $s);
        $s = str_replace(['×', '÷', '٪', '−', '–', '،', '٫'], ['*', '/', '%', '-', '-', ',', '.'], $s);
        return strtr($s, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
    }

    /** @return array the tree: ['n',x] ['v',name] ['neg',a] ['pct',a] ['+',a,b] ['-',a,b] ['*',a,b] ['/',a,b] ['f',name,[args]] */
    public static function parse(string $formula): array
    {
        if (!mb_check_encoding($formula, 'UTF-8')) {
            throw new InvalidArgumentException('نووسینەکە ناخوێندرێتەوە (UTF-8 نییە)');
        }
        $s = trim(self::normalize($formula));
        if ($s === '') {
            throw new InvalidArgumentException('فۆرمول بەتاڵە');
        }
        $p = new self($s);
        $tree = $p->expr();
        if ($p->peek()[0] !== 'end') {
            $p->fail();
        }
        return $tree;
    }

    private array $tok = [];
    private int $i = 0;

    private function __construct(string $s)
    {
        $re = '/\G\s*(?:(?<num>\d+(?:\.\d+)?)|(?<id>[\p{L}_][\p{L}\p{N}_]*)|(?<op>[-+*\/%(),]))\s*/u';
        $pos = 0;
        $len = strlen($s);
        while ($pos < $len) {
            if (!preg_match($re, $s, $m, 0, $pos) || $m[0] === '') {
                $at = mb_substr(substr($s, $pos), 0, 1);
                throw new InvalidArgumentException('نیشانەی «' . $at . '» ناناسرێت');
            }
            $pos += strlen($m[0]);
            if (($m['num'] ?? '') !== '') {
                $this->tok[] = ['num', (float)$m['num']];
            } elseif (($m['id'] ?? '') !== '') {
                $this->tok[] = ['id', $m['id']];
            } else {
                $this->tok[] = ['op', $m['op']];
            }
        }
        $this->tok[] = ['end', ''];
    }

    private function peek(): array
    {
        return $this->tok[$this->i];
    }

    private function take(): array
    {
        return $this->tok[$this->i++];
    }

    private function isOp(string $op): bool
    {
        $t = $this->peek();
        return $t[0] === 'op' && $t[1] === $op;
    }

    private function fail(): void
    {
        $t = $this->peek();
        throw new InvalidArgumentException($t[0] === 'end'
            ? 'فۆرمولەکە تەواو نییە'
            : 'لە «' . ($t[0] === 'num' ? rtrim(rtrim(number_format($t[1], 2, '.', ''), '0'), '.') : $t[1]) . '» دا هەڵەیە');
    }

    private function expr(): array
    {
        $a = $this->term();
        while ($this->isOp('+') || $this->isOp('-')) {
            $op = $this->take()[1];
            $a = [$op, $a, $this->term()];
        }
        return $a;
    }

    private function term(): array
    {
        $a = $this->unary();
        while ($this->isOp('*') || $this->isOp('/')) {
            $op = $this->take()[1];
            $a = [$op, $a, $this->unary()];
        }
        return $a;
    }

    private function unary(): array
    {
        if ($this->isOp('-')) {
            $this->take();
            return ['neg', $this->unary()];
        }
        if ($this->isOp('+')) {
            $this->take();
            return $this->unary();
        }
        $a = $this->atom();
        while ($this->isOp('%')) {
            $this->take();
            $a = ['pct', $a];
        }
        return $a;
    }

    private function atom(): array
    {
        $t = $this->peek();
        if ($t[0] === 'num') {
            $this->take();
            return ['n', $t[1]];
        }
        if ($t[0] === 'id') {
            $this->take();
            $name = mb_strtolower($t[1]);
            if ($this->isOp('(')) {
                if (!isset(self::FUNCS[$name])) {
                    throw new InvalidArgumentException('فانکشنی «' . $t[1] . '» نییە (min، max، abs، round)');
                }
                $this->take();
                $args = [$this->expr()];
                while ($this->isOp(',')) {
                    $this->take();
                    $args[] = $this->expr();
                }
                if (!$this->isOp(')')) {
                    throw new InvalidArgumentException('کەوانەی «)» ی ' . $name . ' نەدۆزرایەوە');
                }
                $this->take();
                [$min, $max] = self::FUNCS[$name];
                if (count($args) < $min || count($args) > $max) {
                    throw new InvalidArgumentException('ژمارەی بەهاکانی ' . $name . ' ڕاست نییە');
                }
                return ['f', $name, $args];
            }
            $var = self::varOf($name);
            if ($var === null) {
                throw new InvalidArgumentException('ناوی «' . $t[1] . '» ناناسرێت — ناوەکان: ' . implode('، ', array_keys(self::vars())));
            }
            return ['v', $var];
        }
        if ($this->isOp('(')) {
            $this->take();
            $a = $this->expr();
            if (!$this->isOp(')')) {
                throw new InvalidArgumentException('کەوانەی «)» نەدۆزرایەوە');
            }
            $this->take();
            return $a;
        }
        $this->fail();
        return []; // never reached
    }

    /** A typed name → the one of vars(), through the aliases. */
    private static function varOf(string $name): ?string
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (self::vars() as $var => [, $aliases]) {
                $map[$var] = $var;
                foreach ($aliases as $a) {
                    $map[mb_strtolower($a)] = $var;
                }
            }
        }
        return $map[$name] ?? null;
    }

    private static function run(array $n, array $v): float
    {
        switch ($n[0]) {
            case 'n':   return (float)$n[1];
            case 'v':   return $v[$n[1]];
            case 'neg': return -self::run($n[1], $v);
            case 'pct': return self::run($n[1], $v) / 100;
            case '+':   return self::run($n[1], $v) + self::run($n[2], $v);
            case '-':   return self::run($n[1], $v) - self::run($n[2], $v);
            case '*':   return self::run($n[1], $v) * self::run($n[2], $v);
            case '/':
                $d = self::run($n[2], $v);
                if (abs($d) < 1e-12) {
                    throw new InvalidArgumentException('دابەشکردن بەسەر سفر');
                }
                return self::run($n[1], $v) / $d;
            case 'f':
                $args = array_map(fn($a) => self::run($a, $v), $n[2]);
                switch ($n[1]) {
                    case 'min':   return (float)min($args);
                    case 'max':   return (float)max($args);
                    case 'abs':   return abs($args[0]);
                    case 'round': return round($args[0], (int)($args[1] ?? 0));
                }
        }
        throw new InvalidArgumentException('فۆرمولەکە ناخوێندرێتەوە');
    }
}
