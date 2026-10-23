<?php
/**
 * Clinic letterhead details for anything the patient takes home.
 *
 * Shared by every printed document so they cannot drift apart:
 *   cases/receipt.php  — پسوولەی وەرگرتنی پارە (the work done)
 *   cases/voucher.php  — قەبزی وەرگرتنی پارە (money actually received)
 *   … and the other receipts, plans and reports that include this file.
 *
 * Defines: $clinicName, $clinicNameEn, $clinicPhone1, $clinicPhone2,
 *          $clinicPhone3, $clinicPhones (phones 1-2 that are filled in, for
 *          implode()), $clinicAddress, $logoUrl, $logoPath, $printAccent
 *
 * All values come from classes/Branding.php (admin/settings.php, tab
 * «ناسنامە و ڕەنگ»), which falls back to Lezan's own details.
 */

require_once __DIR__ . '/../classes/Branding.php';

$clinicName    = Branding::printName();
$clinicNameEn  = Branding::nameEn();
$clinicPhone1  = Branding::phone(1);
$clinicPhone2  = Branding::phone(2);
$clinicPhone3  = Branding::phone(3);
$clinicPhones  = array_values(array_filter([$clinicPhone1, $clinicPhone2], 'strlen'));
$clinicAddress = Branding::address();
$logoUrl       = Branding::logoUrl();   // browser
$logoPath      = Branding::logoPath();  // filesystem, for mPDF
$printAccent   = Branding::printColor();
