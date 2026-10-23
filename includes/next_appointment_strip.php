<?php
/**
 * «مەوعیدی داهاتوو» — the strip every printed receipt shows when the patient
 * has a visit booked (classes/NextAppointment.php).
 *
 * Include it where the strip should sit, with $nextAppointment set to
 * NextAppointment::forPatient()'s result; it prints nothing when that is
 * null. Its styles travel with it, so any receipt can include it as is —
 * several copies on one sheet (?copies=2) just repeat the same rules.
 */
if (empty($nextAppointment) || !is_array($nextAppointment)) {
    return;
}
?>
<style>
.na-strip {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: 3px 8px;
    margin-top: 8px;
    padding: 5px 10px;
    border: 1.5px solid #1b2b3a;
    border-radius: 4px;
    background: #eef6fc;
    color: #1b2b3a;
    font-size: 10pt;
    line-height: 1.5;
    page-break-inside: avoid;
    break-inside: avoid;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}
.na-strip .na-label { font-weight: 700; white-space: nowrap; }
.na-strip .na-when { font-weight: 700; color: #0d5c8a; }
.na-strip .na-doc { color: #3f4a56; font-size: 9pt; }
</style>
<div class="na-strip">
    <span class="na-label">مەوعیدی داهاتوو :</span>
    <span class="na-when"><?= htmlspecialchars($nextAppointment['day']) ?>
        <bdi dir="ltr"><?= htmlspecialchars($nextAppointment['date_display']) ?></bdi>
        — کاتژمێر <bdi dir="ltr"><?= htmlspecialchars($nextAppointment['time']) ?></bdi></span>
    <?php if (!empty($nextAppointment['doctor_name'])): ?>
    <span class="na-doc">— <?= htmlspecialchars($nextAppointment['doctor_name']) ?></span>
    <?php endif; ?>
</div>
