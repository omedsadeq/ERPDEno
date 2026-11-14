<?php
/**
 * بەراورد — copies of the comparison edited by hand (accountant/income-compare.php).
 *
 * The page's two tables, as the accountant changed them (any cell, a row added
 * or taken away), saved under a name; the live report is never touched and
 * keeps reading the real figures. A copy is plain text — titles, headings and
 * rows of cells with their look (CELL_CLASSES / ROW_CLASSES) — and is drawn
 * again with the same markup as the live report, so the page's and the PDF's
 * styles apply to it unchanged.
 */

require_once __DIR__ . '/Database.php';

class IncomeCompareCopy
{
    /** The looks a cell or a row may keep (_income_compare_report.php). */
    private const CELL_CLASSES = ['nm', 'n', 'c', 'rt', 'ch', 'b'];
    private const ROW_CLASSES = ['dept', 'alt', 'tot', 'chg', 'end', 'y'];
    private const MAX_TABLES = 4, MAX_ROWS = 400, MAX_CELLS = 40, MAX_TEXT = 300;

    public static function available(): bool
    {
        return Database::getInstance()->tableExists('income_compare_copies');
    }

    /** Every copy, newest first (without the tables). */
    public static function all(): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT c.id, c.title, c.a_from, c.a_to, c.b_from, c.b_to, c.avg, c.created_at, c.updated_at, u.full_name AS created_by_name
             FROM income_compare_copies c LEFT JOIN users u ON u.id = c.created_by
             ORDER BY c.updated_at DESC, c.id DESC"
        );
    }

    public static function find(int $id): ?array
    {
        $row = Database::getInstance()->fetch("SELECT * FROM income_compare_copies WHERE id = :id", ['id' => $id]);
        if (!$row) {
            return null;
        }
        $row['doc'] = self::clean(json_decode((string)$row['content'], true));
        return $row;
    }

    /**
     * Saves the tables the page sends (JSON) — as a new copy, or over copy $id.
     * Returns the copy's id. The periods are only remembered, to show and to go back to.
     */
    public static function save(?int $id, string $title, string $json, array $periods, int $userId): int
    {
        $doc = self::clean(json_decode($json, true));
        if (!$doc['tables']) {
            throw new InvalidArgumentException('هیچ خشتەیەک نەنێردرا');
        }
        $title = mb_substr(trim(preg_replace('/\s+/u', ' ', $title)), 0, 150);
        if ($title === '') {
            $title = 'کۆپیی بەراورد ' . date('Y-m-d H:i');
        }
        $date = fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
        $data = [
            'title'      => $title,
            'a_from'     => $date($periods['a_from'] ?? null), 'a_to' => $date($periods['a_to'] ?? null),
            'b_from'     => $date($periods['b_from'] ?? null), 'b_to' => $date($periods['b_to'] ?? null),
            'avg'        => !empty($periods['avg']) ? 1 : 0,
            'content'    => json_encode($doc, JSON_UNESCAPED_UNICODE),
            'updated_by' => $userId,
        ];
        $db = Database::getInstance();
        if ($id && self::find($id)) {
            $db->update('income_compare_copies', $data, 'id = :id', ['id' => $id]);
            return $id;
        }
        return (int)$db->insert('income_compare_copies', $data + ['created_by' => $userId]);
    }

    public static function delete(int $id): void
    {
        Database::getInstance()->delete('income_compare_copies', 'id = :id', ['id' => $id]);
    }

    /** Only text and the known looks survive, within sane sizes. */
    public static function clean($doc): array
    {
        $text = fn($v) => mb_substr(trim(str_replace("\r", '', (string)$v)), 0, self::MAX_TEXT);
        $classes = fn($v, array $allowed) => implode(' ', array_values(array_intersect(preg_split('/\s+/', (string)$v), $allowed)));
        $rows = function ($list) use ($text, $classes) {
            $out = [];
            foreach (array_slice(is_array($list) ? $list : [], 0, self::MAX_ROWS) as $row) {
                $cells = [];
                foreach (array_slice(is_array($row['cells'] ?? null) ? $row['cells'] : [], 0, self::MAX_CELLS) as $cell) {
                    $cells[] = ['t' => $text($cell['t'] ?? ''), 'c' => $classes($cell['c'] ?? '', self::CELL_CLASSES)];
                }
                $out[] = ['c' => $classes($row['c'] ?? '', self::ROW_CLASSES), 'cells' => $cells];
            }
            return $out;
        };
        $tables = [];
        foreach (array_slice(is_array($doc['tables'] ?? null) ? $doc['tables'] : [], 0, self::MAX_TABLES) as $t) {
            $cols = [];
            foreach (array_slice(is_array($t['cols'] ?? null) ? $t['cols'] : [], 0, self::MAX_CELLS) as $col) {
                $cols[] = ['t' => $text($col['t'] ?? ''), 'w' => max(0.0, min(40.0, (float)($col['w'] ?? 4)))];
            }
            $tables[] = ['title' => $text($t['title'] ?? ''), 'cols' => $cols, 'body' => $rows($t['body'] ?? []), 'foot' => $rows($t['foot'] ?? [])];
        }
        return ['tables' => $tables];
    }

    /** One table of a copy, in the live report's markup (escaped text, line breaks kept). */
    public static function tableHtml(array $t): string
    {
        $e = fn($v) => nl2br(htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'), false);
        $cell = fn(array $c) => '<td' . ($c['c'] !== '' ? ' class="' . $c['c'] . '"' : '') . '>'
            . ($c['t'] === '' ? '' : '<bdi>' . $e($c['t']) . '</bdi>') . '</td>';
        $row = fn(array $r) => '<tr' . ($r['c'] !== '' ? ' class="' . $r['c'] . '"' : '') . '>' . implode('', array_map($cell, $r['cells'])) . '</tr>';
        $html = '<div class="cr-title">' . $e($t['title']) . '</div><table class="cr"><thead><tr>';
        foreach ($t['cols'] as $col) {
            $html .= '<th width="' . rtrim(rtrim(number_format($col['w'], 2, '.', ''), '0'), '.') . '%">' . $e($col['t']) . '</th>';
        }
        return $html . '</tr></thead><tbody>' . implode('', array_map($row, $t['body'])) . '</tbody><tfoot>'
            . implode('', array_map($row, $t['foot'])) . '</tfoot></table>';
    }
}
