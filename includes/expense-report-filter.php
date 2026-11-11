<?php
/**
 * ڕاپۆرتی خەرجی — the report's filters and figures, shared by the page
 * (admin/expense-reports.php) and its PDF (admin/print-expense-report.php),
 * so the two always show the same expenses.
 *
 * Request parameters:
 *   date_from, date_to   the period
 *   expense_type         all | general | lab | store
 *   category             "kind:id" — a group of any kind (a bare id with a type, as before)
 *   subgroup             a sub-group («لق») id: names its group and kind too
 *   doctor               '' all | 'none' «خەرجی سەنتەر» (written on no doctor) | 'xray' the X-ray
 *                        department's general expenses | a doctor's id
 *   sort, dir            a column of the list (includes/expense-report-layout.php) and asc|desc
 * A group or sub-group narrows the report to its own kind, whatever the type.
 */
require_once __DIR__ . '/expense-subgroup.php';
require_once __DIR__ . '/expense-report-layout.php';

const EXP_REPORT_KINDS = ['general' => 'خەرجی گشتی', 'lab' => 'خەرجی تاقیگە', 'store' => 'خەرجی کۆگا'];
const EXP_REPORT_CATEGORY_TABLES = ['general' => 'expense_categories_general', 'lab' => 'expense_categories_lab', 'store' => 'expense_categories_store'];

/** The filters from a request, checked. */
function exp_report_filters(array $in): array
{
    $date = fn($v, $d) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : $d;
    $f = [
        'from' => $date($in['date_from'] ?? null, date('Y-m-01')),
        'to' => $date($in['date_to'] ?? null, date('Y-m-d')),
        'type' => in_array($in['expense_type'] ?? 'all', ['all', 'general', 'lab', 'store'], true) ? $in['expense_type'] ?? 'all' : 'all',
        'cat_kind' => null, 'cat' => 0, 'sub' => 0,
        'doctor' => '',
    ];
    $cat = (string)($in['category'] ?? '');
    if (preg_match('/^(general|lab|store):(\d+)$/', $cat, $m)) {
        [$f['cat_kind'], $f['cat']] = [$m[1], (int)$m[2]];
    } elseif (ctype_digit($cat) && $f['type'] !== 'all') {
        [$f['cat_kind'], $f['cat']] = [$f['type'], (int)$cat];
    }
    $sub = (int)($in['subgroup'] ?? 0);
    if ($sub > 0) {
        $row = Database::getInstance()->fetch('SELECT id, kind, category_id FROM expense_subgroups WHERE id = ?', [$sub]);
        if ($row) {
            [$f['sub'], $f['cat_kind'], $f['cat']] = [(int)$row['id'], $row['kind'], (int)$row['category_id']];
        }
    }
    if ($f['cat_kind'] !== null) {
        $f['type'] = $f['cat_kind'];
    }
    $doc = (string)($in['doctor'] ?? '');
    $f['doctor'] = $doc === 'none' || $doc === 'xray' || ctype_digit($doc) ? $doc : '';
    $sort = (string)($in['sort'] ?? '');
    $f['sort'] = isset(EXP_REPORT_COLUMNS[$sort]) && EXP_REPORT_COLUMNS[$sort][2] ? $sort : '';
    $f['dir'] = ($in['dir'] ?? '') === 'asc' ? 'asc' : 'desc';
    return $f;
}

/**
 * WHERE (alias e) and its values for one kind, or null when the filters leave
 * that kind out. $withType false: the type itself is not applied (the type
 * chips show every kind under the other filters).
 */
function exp_report_where(string $kind, array $f, bool $withType = true): ?array
{
    if ($withType && $f['type'] !== 'all' && $f['type'] !== $kind) {
        return null;
    }
    if ($f['cat_kind'] !== null && $f['cat_kind'] !== $kind) {
        return null;
    }
    $w = 'e.expense_date BETWEEN :from AND :to';
    $p = ['from' => $f['from'], 'to' => $f['to']];
    if ($f['cat']) {
        $w .= ' AND e.category_id = :cat';
        $p['cat'] = $f['cat'];
    }
    if ($f['sub']) {
        $w .= ' AND e.subgroup_id = :sub';
        $p['sub'] = $f['sub'];
    }
    if ($f['doctor'] === 'none') {
        $w .= ' AND e.doctor_id IS NULL' . ($kind === 'general' ? ' AND e.dept IS NULL' : '');
    } elseif ($f['doctor'] === 'xray') {
        // only a general expense can be the department's
        $w .= $kind === 'general' ? " AND e.dept = 'xray'" : ' AND 0';
    } elseif ($f['doctor'] !== '') {
        $w .= ' AND e.doctor_id = :doc';
        $p['doc'] = (int)$f['doctor'];
    }
    return [$w, $p];
}

/** The expenses the filters keep, every kind together, by date ($newestFirst or oldest first). */
function exp_report_list(array $f, bool $newestFirst = true): array
{
    $db = Database::getInstance();
    $out = [];
    foreach (EXP_REPORT_KINDS as $kind => $label) {
        $scope = exp_report_where($kind, $f);
        if ($scope === null) {
            continue;
        }
        $patient = $kind === 'lab' ? ', p.full_name AS patient_name' : '';
        $patientJoin = $kind === 'lab' ? ' LEFT JOIN patients p ON e.patient_id = p.id' : '';
        $out = array_merge($out, $db->fetchAll(
            "SELECT e.*, '$kind' AS expense_type, '$label' AS type_label, " . ($kind === 'general' ? 'e.dept' : 'NULL') . " AS dept,
                    ec.category_name, s.name AS subgroup_name, u.full_name AS created_by_name, d.full_name AS doctor_name$patient
             FROM " . EXPENSE_SUBGROUP_TABLES[$kind] . " e
             LEFT JOIN " . EXP_REPORT_CATEGORY_TABLES[$kind] . " ec ON e.category_id = ec.id
             LEFT JOIN expense_subgroups s ON s.id = e.subgroup_id
             LEFT JOIN users u ON e.created_by = u.id
             LEFT JOIN users d ON e.doctor_id = d.id$patientJoin
             WHERE {$scope[0]}",
            $scope[1]
        ));
    }
    usort($out, fn($a, $b) => $newestFirst
        ? [$b['expense_date'], (int)$b['id']] <=> [$a['expense_date'], (int)$a['id']]
        : [$a['expense_date'], (int)$a['id']] <=> [$b['expense_date'], (int)$b['id']]);
    // a column the user sorted by
    return ($f['sort'] ?? '') !== '' ? exp_report_sort($out, $f['sort'], $f['dir'] ?? 'desc') : $out;
}

/** Each kind's total and count under the filters (the type itself not applied): kind => [total, count]. */
function exp_report_totals(array $f): array
{
    $db = Database::getInstance();
    $out = [];
    foreach (array_keys(EXP_REPORT_KINDS) as $kind) {
        $scope = exp_report_where($kind, $f, false);
        $row = $scope === null ? null : $db->fetch(
            'SELECT COALESCE(SUM(e.amount), 0) AS total, COUNT(*) AS n FROM ' . EXPENSE_SUBGROUP_TABLES[$kind] . " e WHERE {$scope[0]}",
            $scope[1]
        );
        $out[$kind] = ['total' => (float)($row['total'] ?? 0), 'count' => (int)($row['n'] ?? 0)];
    }
    return $out;
}

/** A kind's totals by group, biggest first ([] when the filters leave it out). */
function exp_report_stats(string $kind, array $f): array
{
    $scope = exp_report_where($kind, $f);
    if ($scope === null) {
        return [];
    }
    return Database::getInstance()->fetchAll(
        'SELECT e.category_id, ec.category_name, SUM(e.amount) AS total, COUNT(*) AS count
         FROM ' . EXPENSE_SUBGROUP_TABLES[$kind] . ' e
         LEFT JOIN ' . EXP_REPORT_CATEGORY_TABLES[$kind] . " ec ON e.category_id = ec.id
         WHERE {$scope[0]}
         GROUP BY e.category_id, ec.category_name ORDER BY total DESC",
        $scope[1]
    );
}

/** A kind's groups divided by their sub-groups (expense_subgroup_totals), under the filters. */
function exp_report_subgroup_totals(string $kind, array $f): array
{
    $scope = exp_report_where($kind, $f);
    return $scope === null ? [] : expense_subgroup_totals($kind, $scope[0], $scope[1]);
}

/** The filters as a query string, with $over changed (a value '' drops it). */
function exp_report_query(array $f, array $over = []): string
{
    $q = [
        'date_from' => $f['from'], 'date_to' => $f['to'], 'expense_type' => $f['type'],
        'category' => $f['cat_kind'] !== null ? $f['cat_kind'] . ':' . $f['cat'] : '',
        'subgroup' => $f['sub'] ?: '', 'doctor' => $f['doctor'],
        'sort' => $f['sort'] ?? '', 'dir' => ($f['sort'] ?? '') !== '' ? ($f['dir'] ?? 'desc') : '',
    ];
    $q = array_filter(array_merge($q, $over), fn($v) => $v !== '' && $v !== null);
    return http_build_query($q, '', '&');
}

/** What the filters say, for the PDF's header: label => value. */
function exp_report_labels(array $f): array
{
    $db = Database::getInstance();
    $doctor = 'هەموو دکتۆرەکان';
    if ($f['doctor'] === 'none') {
        $doctor = 'خەرجی سەنتەر (بێ دکتۆر)';
    } elseif ($f['doctor'] === 'xray') {
        $doctor = 'X-ray (بەشی ئێکسڕەی)';
    } elseif ($f['doctor'] !== '') {
        $doctor = (string)($db->fetch('SELECT full_name FROM users WHERE id = ?', [(int)$f['doctor']])['full_name'] ?? '—');
    }
    $group = 'هەموو گرووپەکان';
    if ($f['cat_kind'] !== null) {
        $group = (string)($db->fetch('SELECT category_name FROM ' . EXP_REPORT_CATEGORY_TABLES[$f['cat_kind']] . ' WHERE id = ?', [$f['cat']])['category_name'] ?? '—');
        if ($f['sub']) {
            $group .= ' · ' . (string)($db->fetch('SELECT name FROM expense_subgroups WHERE id = ?', [$f['sub']])['name'] ?? '');
        }
    }
    return ['type' => $f['type'] === 'all' ? 'هەموو جۆرەکان' : EXP_REPORT_KINDS[$f['type']], 'group' => $group, 'doctor' => $doctor];
}
