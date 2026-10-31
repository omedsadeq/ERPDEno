<?php
/**
 * The patients list as one classic table — drawn by patients/index.php on
 * load and by patients/ajax_search.php for every search and page, so the
 * rows never look different after a search.
 *
 * Needs case-button.php, plan-button.php and AccessGuard loaded by the caller.
 */

/**
 * @param array $patients rows from Patient::getAll / getDoctorPatients / getAllPatientsRestricted
 * @param array $o restricted  another doctor's patients: demographics only
 *                 start       the first row's number on this page
 *                 auth        Auth, for the «کەیس» button
 *                 plans       plan_button_summaries() for the «پلانی چارەسەر» button
 *                 manager     Patient, for the medical-alert check
 *                 search      the search box's text: its words are marked in the rows
 */
function patients_list_table(array $patients, array $o): string
{
    $restricted = !empty($o['restricted']);
    $no = (int)($o['start'] ?? 1);
    // What to mark, read the way Patient::searchTerms() reads the box
    $t = Patient::searchTerms($o['search'] ?? '');
    $o['mark'] = $t['phone'] !== null ? array_unique([$t['words'][0], $t['phone']]) : $t['words'];
    $rows = '';
    foreach ($patients as $p) {
        $rows .= patients_list_row($p, $no++, $restricted, $o);
    }
    return '<table class="pl-table no-responsive"><thead><tr>'
        . '<th class="c-no">#</th><th>نەخۆش</th><th>مۆبایل</th><th class="pl-num">دۆسیە</th><th>قەرز</th><th>تۆمارکردن</th><th class="c-act"></th>'
        . '</tr></thead><tbody>' . $rows . '</tbody></table>';
}

/**
 * The text, escaped, with every occurrence of the searched words in <mark>.
 * Arabic ي/ك in the text match Kurdish ی/ک in the box, as the search does.
 */
function patients_list_mark(string $text, array $words): string
{
    $chars = mb_str_split($text);
    if (!$words || !$chars) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
    // one-for-one letter swaps, so a position in $fold is the same in $text
    $fold = mb_strtolower(strtr($text, ['ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک']));
    $on = [];
    foreach ($words as $w) {
        $w = mb_strtolower((string)$w);
        $len = mb_strlen($w);
        for ($at = 0; $len && ($p = mb_strpos($fold, $w, $at)) !== false; $at = $p + $len) {
            for ($i = $p; $i < $p + $len; $i++) {
                $on[$i] = true;
            }
        }
    }
    $out = '';
    $open = false;
    foreach ($chars as $i => $ch) {
        $hit = !empty($on[$i]);
        if ($hit !== $open) {
            $out .= $hit ? '<mark>' : '</mark>';
            $open = $hit;
        }
        $out .= htmlspecialchars($ch, ENT_QUOTES, 'UTF-8');
    }
    return $out . ($open ? '</mark>' : '');
}

function patients_list_row(array $p, int $no, bool $restricted, array $o): string
{
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $m = fn($v) => patients_list_mark((string)$v, $o['mark'] ?? []);
    $id = (int)$p['id'];
    $view = APP_URL . '/patients/view.php?id=' . $id;
    $female = ($p['gender'] ?? '') === 'female';
    $alert = !$restricted && $o['manager']->hasMedicalAlert($id);
    $debt = $restricted ? 0 : (float)($p['total_debt'] ?? 0);
    $dash = '<span class="pl-muted">—</span>';

    $name = '<a class="pl-name" href="' . $view . '">' . $m($p['full_name']) . '</a>'
        . ($alert ? ' <i class="fas fa-exclamation-triangle pl-alert" title="ئاگادارکردنەوەی پزیشکی"></i>' : '')
        . ' <span class="pl-chip ' . ($female ? 'is-female' : 'is-male') . '">' . ($female ? 'مێ' : 'نێر') . '</span>'
        . ($restricted ? ' <span class="pl-chip is-locked" title="تەنها زانیاری بنەڕەتی"><i class="fas fa-lock"></i> سنوورمەند</span>' : '');
    $sub = $m($p['patient_code'] ?? '');
    if ($restricted && !empty($p['xray_count'])) {
        $sub .= ' · <span class="pl-xray"><i class="fas fa-x-ray"></i> ' . (int)$p['xray_count'] . ' ئێکسڕەی</span>';
    }

    $phone = trim((string)($p['phone'] ?? ''));
    $folders = $restricted ? null : (int)($p['folder_count'] ?? 0);
    $created = !empty($p['created_at']) ? date('Y-m-d', strtotime($p['created_at'])) : '';

    $act = (case_button_allowed($o['auth'], $id) ? case_button($id, ['size' => 'sm']) : '')
        . plan_button($id, $o['plans']);
    $act .= '<a href="' . $view . '" class="pl-act" title="بینین"><i class="fas fa-eye"></i></a>';
    if (!$restricted) {
        $act .= '<a href="' . APP_URL . '/patients/edit.php?id=' . $id . '" class="pl-act" title="دەستکاری"><i class="fas fa-edit"></i></a>'
            . '<a href="' . APP_URL . '/patients/statement.php?patient_id=' . $id . '" class="pl-act is-money" title="کشف حساب"><i class="fas fa-file-invoice-dollar"></i></a>';
        // Every role may delete; a restricted doctor only for patients they may
        // already see (the server re-checks). deletePatient() asks for the password.
        if (!AccessGuard::isRestrictedDoctor() || AccessGuard::doctorCanViewPatient($id)) {
            $nameJs = json_encode($p['full_name'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
            $act .= '<button type="button" class="pl-act is-danger" title="سڕینەوە" onclick="deletePatient(' . $id . ', ' . $e($nameJs) . ')"><i class="fas fa-trash-alt"></i></button>';
        }
    }

    return '<tr class="' . ($alert ? 'has-alert' : '') . ($restricted ? ' is-restricted' : '') . '">'
        . '<td class="c-no">' . $no . '</td>'
        . '<td class="c-patient"><div class="pl-pt"><a class="pl-avatar' . ($female ? ' is-female' : '') . '" href="' . $view . '">'
        .     $e(mb_substr((string)$p['full_name'], 0, 1, 'UTF-8')) . '</a>'
        .     '<div class="pl-who"><div class="pl-line">' . $name . '</div><span class="pl-sub" dir="ltr">' . $sub . '</span></div></div></td>'
        . '<td class="c-phone' . ($phone === '' ? ' is-empty' : '') . '" data-label="مۆبایل">'
        .     ($phone !== '' ? '<a href="tel:' . rawurlencode($phone) . '" dir="ltr">' . $m($phone) . '</a>' : $dash) . '</td>'
        . '<td class="c-folders pl-num' . ($folders === null ? ' is-empty' : '') . '" data-label="دۆسیە">' . ($folders === null ? $dash : $folders) . '</td>'
        . '<td class="c-debt' . ($debt > 0 ? '' : ' is-empty') . '" data-label="قەرز">' . ($debt > 0 ? '<b class="pl-debt">' . number_format($debt) . '</b>' : $dash) . '</td>'
        . '<td class="c-date' . ($created === '' ? ' is-empty' : '') . '" data-label="تۆمارکردن">' . ($created !== '' ? $created : $dash) . '</td>'
        . '<td class="c-act"><div class="pl-actions">' . $act . '</div></td>'
        . '</tr>';
}
