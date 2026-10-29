<?php
/**
 * «کەیس» — one button, on every page that shows a patient, straight into the
 * patient's case. cases/open.php does the work, assets/js/open-case.js the
 * click (loaded by includes/footer.php), assets/css/case-button.css the look.
 */
require_once __DIR__ . '/../classes/AccessGuard.php';

/** Who gets the button: a doctor on a patient they may reach, the admin and reception. */
function case_button_allowed(Auth $auth, $patientId) {
    if ((int)$patientId <= 0) {
        return false;
    }
    if ($auth->isAdmin() || $auth->isReceptionist()) {
        return true;
    }
    return $auth->isDoctor() && AccessGuard::doctorCanViewPatient((int)$patientId);
}

/**
 * The button. $o['doctor'] — whose case it is when the page knows (an
 * appointment's or a plan's doctor), so the admin is not asked; $o['size'] —
 * 'sm' in a table, 'lg' on a card; $o['class'] — the page's own extras.
 */
function case_button($patientId, array $o = []) {
    $size = in_array($o['size'] ?? '', ['sm', 'lg'], true) ? ' case-go-btn--' . $o['size'] : '';
    $extra = !empty($o['class']) ? ' ' . htmlspecialchars($o['class'], ENT_QUOTES, 'UTF-8') : '';
    return '<button type="button" class="case-go-btn' . $size . $extra . '"'
        . ' onclick="event.stopPropagation(); openPatientCase(' . (int)$patientId . ', this, ' . (int)($o['doctor'] ?? 0) . ')"'
        . ' title="ڕاستەوخۆ بۆ چارتی ددانی کەیسەکە — ئەگەر نەبێت، دروست دەبێت">'
        . '<i class="fas fa-tooth"></i><span>کەیس</span></button>';
}
