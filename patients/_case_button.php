<?php
/**
 * «کەیس» on a row of the patients list (index.php and ajax_search.php):
 * straight into the patient's case. The click is handled by openPatientCase()
 * and the open_case action at the top of patients/index.php.
 */
function patient_case_button($patientId) {
    return '<button type="button" class="btn pt-case-btn" onclick="openPatientCase(' . (int)$patientId . ', this)"'
        . ' title="ڕاستەوخۆ بۆ چارتی ددانی کەیسەکە — ئەگەر نەبێت، دروست دەبێت">'
        . '<i class="fas fa-tooth"></i><span>کەیس</span></button>';
}

/** Who gets the button: a doctor on a patient they may reach, the admin and reception. */
function patient_case_button_allowed(Auth $auth, $patientId) {
    if ($auth->isAdmin() || $auth->isReceptionist()) {
        return true;
    }
    return $auth->isDoctor() && AccessGuard::doctorCanViewPatient((int)$patientId);
}
