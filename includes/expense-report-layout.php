<?php
/**
 * ڕاپۆرتی خەرجی — how the list is laid out, and in what order its rows come.
 *
 * The admin arranges the list on the page itself («ڕێکخستنی پەڕە»): which
 * columns it shows, in what order and under what name, and whether the
 * figures above it and the summary beside it are shown. The arrangement is
 * kept in settings (expense_report_layout) and the PDF prints the same
 * columns. Any column can sort the list (?sort=<column>&dir=asc|desc); the
 * PDF keeps that order too.
 */
require_once __DIR__ . '/../classes/Settings.php';

/** Every column the list can show: key => [heading, shown by default, sortable]. */
const EXP_REPORT_COLUMNS = [
    'num'     => ['#', true, false],
    'date'    => ['بەروار', true, true],
    'type'    => ['جۆر', true, true],
    'group'   => ['گرووپ', true, true],
    'sub'     => ['لق', false, true],
    'desc'    => ['وەسف', true, true],
    'doctor'  => ['دکتۆر', true, true],
    'vendor'  => ['شوێن / فرۆشیار', false, true],
    'receipt' => ['ژمارەی وەسڵ', false, true],
    'patient' => ['نەخۆش', false, true],
    'kind'    => ['ئاسایی / ئیمپلانت', false, true],
    'method'  => ['شێوازی پارەدان', false, true],
    'notes'   => ['تێبینی', false, true],
    'by'      => ['تۆمارکەر', false, true],
    'created' => ['کاتی تۆمار', false, true],
    'amount'  => ['بڕ', true, true],
];
/** The parts of the page that can be hidden. */
const EXP_REPORT_PANELS = ['chips' => 'ژمارەکانی سەرەوە (کۆی گشتی، جۆرەکان، ئەم مانگە)', 'summary' => 'پوختەی گرووپەکان (لای چەپ)'];
const EXP_REPORT_LAYOUT_SETTING = 'expense_report_layout';

/**
 * The arrangement: ['cols' => [[key, label, hidden], …] in order, 'panels' =>
 * [name => shown]]. A column added to the catalog later comes in at the end,
 * as the catalog has it; one dropped from it is let go.
 */
function exp_report_layout(): array
{
    $saved = json_decode((string)Settings::get(EXP_REPORT_LAYOUT_SETTING, ''), true);
    $saved = is_array($saved) ? $saved : [];
    $cols = [];
    foreach ((array)($saved['cols'] ?? []) as $c) {
        $key = (string)($c['key'] ?? '');
        if (isset(EXP_REPORT_COLUMNS[$key]) && !isset($cols[$key])) {
            $label = trim((string)($c['label'] ?? ''));
            $cols[$key] = ['key' => $key, 'label' => $label !== '' ? $label : EXP_REPORT_COLUMNS[$key][0],
                           'default' => EXP_REPORT_COLUMNS[$key][0], 'hidden' => !empty($c['hidden'])];
        }
    }
    foreach (EXP_REPORT_COLUMNS as $key => [$label, $shown]) {
        $cols[$key] ??= ['key' => $key, 'label' => $label, 'default' => $label, 'hidden' => !$shown];
    }
    // the amount is what the list is for: it is never hidden
    $cols['amount']['hidden'] = false;
    $panels = [];
    foreach (EXP_REPORT_PANELS as $name => $_) {
        $panels[$name] = !array_key_exists($name, (array)($saved['panels'] ?? [])) || !empty($saved['panels'][$name]);
    }
    return ['cols' => array_values($cols), 'panels' => $panels];
}

/** The columns shown, in order. */
function exp_report_visible_columns(array $layout): array
{
    return array_values(array_filter($layout['cols'], fn($c) => !$c['hidden']));
}

/** Saves what the arrangement dialog sent (cols: [{key,label,hidden}], panels: {name: bool}). */
function exp_report_layout_save(array $in, int $userId): void
{
    $cols = [];
    foreach ((array)($in['cols'] ?? []) as $c) {
        $key = (string)($c['key'] ?? '');
        if (!isset(EXP_REPORT_COLUMNS[$key]) || isset($cols[$key])) {
            continue;
        }
        $label = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($c['label'] ?? ''))), 0, 60);
        $cols[$key] = ['key' => $key, 'label' => $label === EXP_REPORT_COLUMNS[$key][0] ? '' : $label,
                       'hidden' => $key !== 'amount' && !empty($c['hidden'])];
    }
    $panels = [];
    foreach (EXP_REPORT_PANELS as $name => $_) {
        $panels[$name] = !empty(((array)($in['panels'] ?? []))[$name]);
    }
    $old = Settings::get(EXP_REPORT_LAYOUT_SETTING, '');
    $new = json_encode(['cols' => array_values($cols), 'panels' => $panels], JSON_UNESCAPED_UNICODE);
    Settings::set(EXP_REPORT_LAYOUT_SETTING, $new, 'json', 'ڕێکخستنی لیستی ڕاپۆرتی خەرجی');
    if (class_exists('AuditLog')) {
        AuditLog::log('expense_report_layout', 'settings', 0, ['value' => $old], ['value' => $new], $userId);
    }
}

function exp_report_layout_reset(int $userId): void
{
    $old = Settings::get(EXP_REPORT_LAYOUT_SETTING, '');
    Settings::set(EXP_REPORT_LAYOUT_SETTING, '', 'json', 'ڕێکخستنی لیستی ڕاپۆرتی خەرجی');
    if (class_exists('AuditLog')) {
        AuditLog::log('expense_report_layout', 'settings', 0, ['value' => $old], ['value' => ''], $userId);
    }
}

/** Who an expense is on, as words. */
function exp_report_who(array $exp): string
{
    if (!empty($exp['doctor_name'])) {
        return (string)$exp['doctor_name'];
    }
    return ($exp['dept'] ?? null) === 'xray' ? 'X-ray' : 'خەرجی سەنتەر';
}

/** One cell's plain value (for sorting and for the PDF). */
function exp_report_value(string $key, array $exp)
{
    $kind = $exp['expense_type'] ?? '';
    return match ($key) {
        'date' => (string)$exp['expense_date'],
        'type' => (string)($exp['type_label'] ?? ''),
        'group' => (string)($exp['category_name'] ?? ''),
        'sub' => (string)($exp['subgroup_name'] ?? ''),
        'desc' => (string)($exp['description'] ?? ''),
        'doctor' => exp_report_who($exp),
        'vendor' => (string)($kind === 'lab' ? ($exp['lab_name'] ?? '') : ($kind === 'store' ? ($exp['supplier'] ?? '') : ($exp['vendor'] ?? ''))),
        'receipt' => (string)($kind === 'store' ? ($exp['invoice_number'] ?? '') : ($exp['receipt_number'] ?? '')),
        'patient' => (string)($exp['patient_name'] ?? ''),
        'kind' => $kind === 'store' ? (($exp['expense_kind'] ?? '') === 'implant' ? 'ئیمپلانت' : 'ئاسایی') : '',
        'method' => match ((string)($exp['payment_method'] ?? '')) {
            'cash' => 'کاش', 'card' => 'کارت', 'transfer' => 'گواستنەوە', 'check' => 'چەک', default => (string)($exp['payment_method'] ?? ''),
        },
        'notes' => (string)($exp['notes'] ?? ''),
        'by' => (string)($exp['created_by_name'] ?? ''),
        'created' => (string)($exp['created_at'] ?? ''),
        'amount' => (float)$exp['amount'],
        default => '',
    };
}

/** The list in the order asked: a column and asc|desc; the date (then the row) breaks ties. */
function exp_report_sort(array $list, string $key, string $dir): array
{
    if (!isset(EXP_REPORT_COLUMNS[$key]) || !EXP_REPORT_COLUMNS[$key][2]) {
        return $list;
    }
    $sign = $dir === 'asc' ? 1 : -1;
    usort($list, function ($a, $b) use ($key, $sign) {
        $x = exp_report_value($key, $a);
        $y = exp_report_value($key, $b);
        $c = is_float($x) ? $x <=> $y : strcmp(mb_strtolower((string)$x), mb_strtolower((string)$y));
        return $c !== 0 ? $c * $sign
            : [$b['expense_date'], (int)$b['id']] <=> [$a['expense_date'], (int)$a['id']];
    });
    return $list;
}
