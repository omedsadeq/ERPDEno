<?php

/** Use the same clinic details and logo as the printed invoices. */
function dh_visit_report_letterhead(Database $db, bool $forPdf = false): string
{
    // Keep the invoice branding variables local to this renderer.
    require __DIR__ . '/clinic_branding.php';
    $escape = static function ($value): string {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    };
    $logo = $forPdf ? $logoPath : $logoUrl;
    return '<table class="vr-letterhead" dir="rtl" width="100%" style="width:100%;border-collapse:collapse;border-bottom:2px solid #0f766e;margin-bottom:14px;font-family:nrt,sans-serif;">'
        . '<tr><td class="vr-letterhead-ku" width="40%" style="width:40%;border:0;padding:0 0 10px;vertical-align:middle;text-align:right;">'
        . '<div class="vr-letterhead-name-ku" style="font-size:20px;font-weight:bold;color:#0f766e;line-height:1.5;">' . $escape($clinicName) . '</div>'
        . '<div class="vr-letterhead-address" style="font-size:11px;color:#475569;line-height:1.7;">' . $escape($clinicAddress) . '</div></td>'
        . '<td class="vr-letterhead-logo" width="20%" style="width:20%;border:0;padding:0 10px 10px;vertical-align:middle;text-align:center;">'
        . '<img src="' . $escape($logo) . '" alt="' . $escape($clinicNameEn) . '" style="width:76px;height:auto;"></td>'
        . '<td class="vr-letterhead-en" width="40%" dir="ltr" style="width:40%;border:0;padding:0 0 10px;vertical-align:middle;text-align:left;direction:ltr;">'
        . '<div class="vr-letterhead-name-en" style="font-size:16px;font-weight:bold;color:#0f766e;line-height:1.5;">' . $escape($clinicNameEn) . '</div>'
        . '<div class="vr-letterhead-phones" style="font-size:12px;color:#475569;line-height:1.7;direction:ltr;">' . implode('<br>', array_map($escape, $clinicPhones)) . '</div>'
        . '</td></tr></table>';
}
