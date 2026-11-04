<?php
/**
 * Sub-groups («لق») of the expense groups: کارەبا → مۆلیدە / حکومی. They are
 * managed on admin/expense-groups.php; this is what the expense pages, the
 * report and the prints share. assets/js/expense-subgroups.js fills the «لق»
 * selects from the group chosen beside them.
 *
 * $kind is general, lab or store. Totals by group stay as they were: a
 * sub-group only divides its group further.
 */
require_once __DIR__ . '/../classes/Database.php';

const EXPENSE_SUBGROUP_TABLES = ['general' => 'expenses_general', 'lab' => 'expenses_lab', 'store' => 'expenses_store'];

/** Group id => its sub-groups, hidden ones included. */
function expense_subgroups_by_group(string $kind): array {
    $rows = Database::getInstance()->fetchAll(
        "SELECT id, category_id, name, is_active FROM expense_subgroups
         WHERE kind = :kind ORDER BY is_active DESC, id",
        ['kind' => $kind]
    );
    $byGroup = [];
    foreach ($rows as $row) {
        $byGroup[(int)$row['category_id']][] = $row;
    }
    return $byGroup;
}

/** The sub-group the form sent, if it belongs to this group: a group changed in the form must not keep the old one. */
function expense_subgroup_posted(string $kind, int $categoryId): ?int {
    $id = (int)($_POST['subgroup_id'] ?? 0);
    if ($id <= 0 || $categoryId <= 0) {
        return null;
    }
    $row = Database::getInstance()->fetch(
        "SELECT id FROM expense_subgroups WHERE id = :id AND kind = :kind AND category_id = :category",
        ['id' => $id, 'kind' => $kind, 'category' => $categoryId]
    );
    return $row ? $id : null;
}

/**
 * Group id => what its sub-groups add up to, for the summaries. A group none of
 * whose expenses has a sub-group is left out: it has nothing to divide.
 * $where filters expenses under the alias e.
 */
function expense_subgroup_totals(string $kind, string $where, array $params): array {
    $table = EXPENSE_SUBGROUP_TABLES[$kind];
    $rows = Database::getInstance()->fetchAll(
        "SELECT e.category_id, s.name, SUM(e.amount) AS total, COUNT(*) AS count
         FROM {$table} e
         LEFT JOIN expense_subgroups s ON s.id = e.subgroup_id
         WHERE {$where}
         GROUP BY e.category_id, s.id, s.name
         ORDER BY total DESC",
        $params
    );
    $byGroup = [];
    foreach ($rows as $row) {
        $byGroup[(int)$row['category_id']][] = $row;
    }
    return array_filter($byGroup, fn($parts) => count($parts) > 1 || $parts[0]['name'] !== null);
}

/** One sub-group's name in a summary; expenses of the group that have none are «بێ لق». */
function expense_subgroup_name(array $part): string {
    return $part['name'] ?? 'بێ لق';
}

/** «کارەبا · مۆلیدە», or the group alone, for the report and the prints. */
function expense_group_label(array $expense): string {
    $group = $expense['category_name'] ?? '-';
    return empty($expense['subgroup_name']) ? $group : $group . ' · ' . $expense['subgroup_name'];
}

/** The «لق» row of an add or edit form: it opens only for a group that has sub-groups. */
function expense_subgroup_field(string $id = ''): string {
    return '<div class="exp-form-grid" data-subgroup-row style="margin-top: 10px; display: none;">'
        . '<div class="exp-form-field exp-field-full"><label><i class="fas fa-code-branch"></i> لق</label>'
        . '<select name="subgroup_id"' . ($id !== '' ? ' id="' . $id . '"' : '')
        . ' data-subgroups="active" data-group-field="category_id" data-empty="هەڵبژێرە..."></select>'
        . '</div></div>';
}

/** The same in the filters above a list, where the hidden ones count too. */
function expense_subgroup_filter(int $selected): string {
    return '<div class="expense-filter-group" data-subgroup-row style="display: none;"><label>لق</label>'
        . '<select name="subgroup" data-subgroups="all" data-group-field="category"'
        . ' data-empty="هەموو لقەکان" data-selected="' . $selected . '"></select></div>';
}

/** Under the group's badge in a list. */
function expense_subgroup_tag(array $expense): string {
    return empty($expense['subgroup_name'])
        ? ''
        : '<small class="expense-sub">' . htmlspecialchars($expense['subgroup_name']) . '</small>';
}

/** A group's sub-groups under it in a summary panel. */
function expense_subgroup_summary(array $parts): string {
    if (!$parts) {
        return '';
    }
    $html = '<div class="expense-summary-subs">';
    foreach ($parts as $part) {
        $html .= '<div><span>' . htmlspecialchars(expense_subgroup_name($part)) . '</span><b>'
            . number_format((float)$part['total']) . '</b></div>';
    }
    return $html . '</div>';
}

function expense_subgroup_style(): void { ?>
<style>
.expense-sub { display: block; margin-top: 2px; font-size: 11px; color: #666; }
.expense-summary-item:has(+ .expense-summary-subs), .summary-item:has(+ .expense-summary-subs) { padding-bottom: 2px; border-bottom: none; }
.expense-summary-subs { padding: 0 26px 6px 0; border-bottom: 1px solid #f0f0f0; }
.expense-summary-subs:last-child { border-bottom: none; }
.expense-summary-subs div { display: flex; justify-content: space-between; padding: 1px 0; font-size: 11px; color: #777; }
.expense-summary-subs b { font-weight: 600; color: #555; }
</style>
<?php }

/** What a page with the «لق» fields needs: the look, the list, the script. */
function expense_subgroup_assets(array $byGroup): void {
    expense_subgroup_style();
    $list = json_encode((object)$byGroup, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    $script = APP_URL . '/assets/js/expense-subgroups.js?v=' . @filemtime(__DIR__ . '/../assets/js/expense-subgroups.js');
    echo '<script>window.expenseSubgroups = ' . $list . ';</script>' . "\n";
    echo '<script src="' . $script . '"></script>' . "\n";
}
