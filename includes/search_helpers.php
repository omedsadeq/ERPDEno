<?php
/**
 * Smart search helpers for visits search (visits.php + ajax/ts_visits.php).
 *
 * - search_fold_text():   normalizes a user query — Arabic-Indic digits → ASCII,
 *                         Arabic/Kurdish letter variants folded (ي→ی, ك→ک, أإآ→ا, ة/ە→ه).
 * - search_visits_conditions(): builds the per-term AND / per-field OR SQL for a
 *                         search string. Every placeholder name is unique —
 *                         PDO emulation is off, named placeholders must NEVER be reused.
 */

/**
 * Which visits.* doctor columns a filter choice covers.
 *
 * $field: '' = any role the doctor holds on the visit,
 *         'assigned'  = د. بینراو, 'checkup' = د. فەحس, 'treatment' = د. ئیش.
 *
 * $hasNewCols is false until the 2026_07_20 migration runs; the two per-type
 * columns are then dropped from the list (and an explicit checkup/treatment
 * pick degrades to "any" rather than matching a column that does not exist).
 */
function visits_doctor_columns(string $field, bool $hasNewCols = true): array {
    if (!$hasNewCols && ($field === 'checkup' || $field === 'treatment')) {
        $field = '';
    }
    switch ($field) {
        case 'assigned':  return ['assigned_doctor_id'];
        case 'checkup':   return ['checkup_doctor_id'];
        case 'treatment': return ['treatment_doctor_id'];
        default:
            return $hasNewCols
                ? ['requested_doctor_id', 'assigned_doctor_id', 'checkup_doctor_id', 'treatment_doctor_id']
                : ['requested_doctor_id', 'assigned_doctor_id'];
    }
}

/**
 * WHERE fragment matching one doctor against the chosen column(s).
 * Placeholders are prefixed per call site — PDO emulation is off, so a reused
 * named placeholder is fatal.
 */
function visits_doctor_condition(int $doctorId, string $field, array &$params, string $prefix = 'doc', bool $hasNewCols = true, string $t = 'v.'): string {
    $parts = [];
    foreach (visits_doctor_columns($field, $hasNewCols) as $i => $col) {
        $p = $prefix . '_' . $i;
        $parts[] = "{$t}{$col} = :{$p}";
        $params[$p] = $doctorId;
    }
    return '(' . implode(' OR ', $parts) . ')';
}

/** Whitelist for the ?doctor_field= combobox. */
function visits_doctor_field(?string $raw): string {
    return in_array($raw, ['assigned', 'checkup', 'treatment'], true) ? $raw : '';
}

function search_fold_text(string $s): string {
    // Arabic-Indic (٠-٩) and Extended Arabic-Indic (۰-۹) digits → ASCII
    $s = strtr($s, [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ]);
    // Arabic ↔ Kurdish/Farsi letter variants
    $s = strtr($s, [
        'ي' => 'ی', 'ك' => 'ک',
        'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا',
        'ة' => 'ه', 'ە' => 'ه',
    ]);
    return trim(preg_replace('/\s+/u', ' ', $s));
}

/** SQL expression folding the same letter variants on the stored name column. */
function search_name_fold_expr(string $col): string {
    return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE($col,"
         . "'ي','ی'),'ك','ک'),'أ','ا'),'إ','ا'),'آ','ا'),'ة','ه'),'ە','ه')";
}

/** SQL expression stripping spaces/dashes from the stored phone column. */
function search_phone_fold_expr(string $col): string {
    return "REPLACE(REPLACE($col,' ',''),'-','')";
}

/**
 * Build the WHERE fragment for a visits search: every term must match (AND),
 * each term may match any field (OR). Caller wraps the result in parentheses.
 *
 * @param string $search        raw user query
 * @param array  $params        bound params, appended to by reference
 * @param string $prefix        unique placeholder prefix per call site
 * @param bool   $includeExtras also match procedure_treatment / referral_source
 * @param string $t             table alias prefix, e.g. 'v.' or ''
 */
function search_visits_conditions(string $search, array &$params, string $prefix = 's', bool $includeExtras = true, string $t = 'v.'): string {
    $folded    = search_fold_text($search);
    $nameExpr  = search_name_fold_expr($t . 'patient_name');
    $phoneExpr = search_phone_fold_expr($t . 'mobile_number');

    $conds = [];
    foreach (preg_split('/\s+/u', $folded) as $i => $term) {
        $term = trim($term);
        if ($term === '') continue;

        $nName = "{$prefix}{$i}_name";
        $fields = ["$nameExpr LIKE :$nName"];
        $params[$nName] = '%' . $term . '%';

        // Phone match on digits only (query and column both stripped of separators)
        $digits = preg_replace('/\D+/', '', $term);
        if ($digits !== '') {
            $nPhone = "{$prefix}{$i}_phone";
            $fields[] = "$phoneExpr LIKE :$nPhone";
            $params[$nPhone] = '%' . $digits . '%';
        }

        if ($includeExtras) {
            $nProc = "{$prefix}{$i}_proc";
            $nRef  = "{$prefix}{$i}_ref";
            $fields[] = search_name_fold_expr($t . 'procedure_treatment') . " LIKE :$nProc";
            $fields[] = search_name_fold_expr($t . 'referral_source') . " LIKE :$nRef";
            $params[$nProc] = '%' . $term . '%';
            $params[$nRef]  = '%' . $term . '%';
        }

        $conds[] = '(' . implode(' OR ', $fields) . ')';
    }

    return $conds ? implode(' AND ', $conds) : '1=1';
}
