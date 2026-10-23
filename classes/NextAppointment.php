<?php
/**
 * NextAppointment — مەوعیدی داهاتوو, for the printed receipts.
 *
 * Every sheet the clinic hands a patient (visit invoice, payment voucher —
 * instalment collections included —, arch-work receipt, X-ray form, advance
 * receipt) carries the patient's next booked visit when there is one, so they
 * leave the counter knowing when to come back.
 *
 * "Next" is the rule the case page's «مەوعیدی داهاتوو» chip already uses
 * (cases/view.php): an appointment still ahead — status scheduled/confirmed,
 * on a later day, or today at a time that has not passed yet. When several
 * are booked, the one the sheet's own treatment asked for wins: the same case
 * first (a doctor booking from the case links it — api/notifications.php's
 * complete_case, cases/view.php's procedure log), then the same doctor, then
 * simply the earliest.
 *
 * A receipt must always print: any failure here reads as "no appointment".
 */

require_once __DIR__ . '/Database.php';

class NextAppointment
{
    /** PHP date('w') → Kurdish day name (same names as DoctorSchedule). */
    private const DAYS = [
        0 => 'یەکشەممە', 1 => 'دووشەممە', 2 => 'سێشەممە', 3 => 'چوارشەممە',
        4 => 'پێنجشەممە', 5 => 'هەینی', 6 => 'شەممە',
    ];

    /**
     * @return array|null ['date' => 'Y-m-d', 'time' => 'H:i', 'day' => string,
     *                     'date_display' => 'Y/m/d', 'doctor_name' => ?string]
     */
    public static function forPatient($patientId, $caseId = 0, $doctorId = 0)
    {
        $patientId = (int)$patientId;
        $caseId = (int)$caseId;
        $doctorId = (int)$doctorId;
        if ($patientId <= 0) {
            return null;
        }

        // Preference terms only for what the caller actually knows — an
        // unknown case/doctor must not quietly favour rows WITHOUT one.
        $params = ['pid' => $patientId];
        $order = [];
        if ($caseId > 0) {
            $order[] = '(COALESCE(a.case_id, 0) = :cid) DESC';
            $params['cid'] = $caseId;
        }
        if ($doctorId > 0) {
            $order[] = '(COALESCE(a.doctor_id, 0) = :did) DESC';
            $params['did'] = $doctorId;
        }
        $order[] = 'a.appointment_date';
        $order[] = 'a.appointment_time';

        try {
            $row = Database::getInstance()->fetch(
                "SELECT a.appointment_date, a.appointment_time, u.full_name AS doctor_name
                 FROM appointments a
                 LEFT JOIN users u ON u.id = a.doctor_id
                 WHERE a.patient_id = :pid
                   AND a.status IN ('scheduled', 'confirmed')
                   AND (a.appointment_date > CURDATE()
                        OR (a.appointment_date = CURDATE() AND a.appointment_time >= CURTIME()))
                 ORDER BY " . implode(', ', $order) . "
                 LIMIT 1",
                $params
            );
        } catch (Throwable $e) {
            error_log('NextAppointment lookup failed: ' . $e->getMessage());
            return null;
        }
        if (!$row) {
            return null;
        }

        $ts = strtotime($row['appointment_date'] . ' ' . $row['appointment_time']);
        if ($ts === false) {
            return null;
        }
        return [
            'date'         => date('Y-m-d', $ts),
            'time'         => date('H:i', $ts),
            'day'          => self::DAYS[(int)date('w', $ts)] ?? '',
            'date_display' => date('Y/m/d', $ts),
            'doctor_name'  => $row['doctor_name'] ?: null,
        ];
    }

    /** One-line text form: «یەکشەممە 2026/10/05 — کاتژمێر 10:30 — د.ناو». */
    public static function text(array $appt)
    {
        return trim($appt['day'] . ' ' . $appt['date_display'])
            . ' — کاتژمێر ' . $appt['time']
            . (!empty($appt['doctor_name']) ? ' — ' . $appt['doctor_name'] : '');
    }
}
