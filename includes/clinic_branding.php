<?php
/**
 * Clinic letterhead details for anything the patient takes home.
 *
 * Shared by every printed document so they cannot drift apart:
 *   cases/receipt.php  — پسوولەی وەرگرتنی پارە (the work done)
 *   cases/voucher.php  — قەبزی وەرگرتنی پارە (money actually received)
 *
 * Defines: $clinicName, $clinicNameEn, $clinicPhone1, $clinicPhone2,
 *          $clinicAddress, $logoUrl
 *
 * Expects $db (Database) in the including scope.
 */

$settingsRows = $db->fetchAll("SELECT setting_key, setting_value FROM settings");
$settings = [];
foreach ($settingsRows as $s) {
    if (trim((string)$s['setting_value']) !== '') {
        $settings[$s['setting_key']] = $s['setting_value'];
    }
}
$clinicName    = $settings['clinic_name_ku'] ?? 'لیزان بۆ پزیشکی ددان';
$clinicNameEn  = $settings['clinic_name_en'] ?? 'LEZAN DENTAL CLINICS';
$clinicPhone1  = $settings['clinic_phone'] ?? '0750 693 2626';
$clinicPhone2  = $settings['clinic_phone_2'] ?? '0750 632 7575';
$clinicAddress = $settings['clinic_address'] ?? 'سۆران: ترافیک لایەتی دەروازەی سۆران، بەرامبەر میلان کڵاس';

// The settings table still holds the install-time placeholders on this system.
// Printing "0750-000-0000" on a patient receipt is worse than printing the real
// number off the pad, so ignore the known placeholder values.
// Once admin/settings.php carries the real details these fall away by themselves.
$isPlaceholderPhone = function ($v) {
    $digits = preg_replace('/\D/', '', (string)$v);
    return $digits === '' || preg_match('/0{5,}/', $digits);
};
if ($isPlaceholderPhone($clinicPhone1)) { $clinicPhone1 = '0750 693 2626'; }
if ($isPlaceholderPhone($clinicPhone2)) { $clinicPhone2 = '0750 632 7575'; }
if (in_array(trim($clinicAddress), ['هەولێر - عیراق', 'Erbil - Iraq'], true)) {
    $clinicAddress = 'سۆران: ترافیک لایەتی دەروازەی سۆران، بەرامبەر میلان کڵاس';
}

$logoUrl = APP_URL . '/assets/images/logo.png';
