<?php
/**
 * VisitDoctorSync — keeps visits.assigned_doctor_id and the per-type doctor
 * (دکتۆری فەحس / دکتۆری ئیش) in sync with whoever is chosen for — or actually
 * calls — the patient.
 *
 * Both columns reference doctors.id, like the other visit doctor columns.
 * waiting_room.doctor_id references users.id, so callers coming from the
 * waiting-room flow must map through doctorsIdFromUserId() first.
 *
 * Write cascade — "the latest, most specific choice wins":
 *   1. new_visit.php form            → doctor from the form
 *   2. send-to-waiting-room dialogs  → doctor picked in the dialog
 *   3. waiting-room "call" action    → the doctor who actually takes the patient
 *
 * Every write is fail-soft: if the migration adding the columns has not run
 * yet, or anything else goes wrong, the calling flow must never break.
 */
class VisitDoctorSync {

    /** @var bool|null request-level cache for the column-existence probe */
    private static $available = null;

    /** treatment → treatment_doctor_id; checkup/xray → checkup_doctor_id */
    public static function columnForVisitType(?string $visitType): ?string {
        if ($visitType === 'treatment') {
            return 'treatment_doctor_id';
        }
        if ($visitType === 'checkup' || $visitType === 'xray') {
            return 'checkup_doctor_id';
        }
        return null;
    }

    /**
     * Map a users.id (waiting_room domain) to doctors.id (visits domain) via
     * the doctors.user_id bridge, falling back to the legacy name match.
     * Returns 0 when there is no clinical record (e.g. x-ray department account).
     */
    public static function doctorsIdFromUserId(Database $db, int $userId): int {
        if ($userId <= 0) {
            return 0;
        }
        $row = $db->fetch(
            "SELECT d.id FROM doctors d WHERE d.user_id = :uid
             UNION
             SELECT d.id FROM doctors d JOIN users u ON u.full_name = d.name AND u.id = :uid2
             LIMIT 1",
            ['uid' => $userId, 'uid2' => $userId]
        );
        return (int)($row['id'] ?? 0);
    }

    /**
     * Write the received doctor and visit-type doctor together. Preserve the
     * patient's original request and reception's recorded assignment source.
     *
     * Target: the explicit $visitId when given (guarded to the patient when
     * known), otherwise the patient's latest visit on DATE($dateSource) —
     * the same targeting rule waiting-room.php uses for checkin_time sync.
     *
     * @param int|null    $visitId    exact visits.id, or null for latest-of-day
     * @param int         $patientId  patients.id (0 = unknown, only valid with $visitId)
     * @param string|null $visitType  checkup | treatment | xray
     * @param int         $doctorsId  doctors.id (NOT users.id)
     * @param string|null $dateSource datetime the visit date is derived from (default: now)
     */
    public static function assignToVisit(Database $db, ?int $visitId, int $patientId, ?string $visitType, int $doctorsId, ?string $dateSource = null): void {
        try {
            $column = self::columnForVisitType($visitType);
            if ($column === null || !self::available($db)) {
                return;
            }
            if ($doctorsId <= 0 || !$db->fetch("SELECT id FROM doctors WHERE id = :id", ['id' => $doctorsId])) {
                return;
            }

            if ($visitId !== null && $visitId > 0) {
                if ($patientId > 0) {
                    $db->query(
                        "UPDATE visits SET $column = :doc, assigned_doctor_id = :assigned
                         WHERE id = :vid AND patient_id = :pid AND visit_type = :visit_type",
                        ['doc' => $doctorsId, 'assigned' => $doctorsId, 'vid' => $visitId, 'pid' => $patientId, 'visit_type' => $visitType]
                    );
                } else {
                    $db->query(
                        "UPDATE visits SET $column = :doc, assigned_doctor_id = :assigned
                         WHERE id = :vid AND visit_type = :visit_type",
                        ['doc' => $doctorsId, 'assigned' => $doctorsId, 'vid' => $visitId, 'visit_type' => $visitType]
                    );
                }
                return;
            }

            if ($patientId <= 0) {
                return;
            }
            $db->query(
                "UPDATE visits SET $column = :doc, assigned_doctor_id = :assigned
                 WHERE id = (
                     SELECT id FROM (
                         SELECT id FROM visits
                         WHERE patient_id = :pid AND visit_date = DATE(:dsrc) AND visit_type = :visit_type
                         ORDER BY id DESC LIMIT 1
                     ) latest_visit
                 )",
                ['doc' => $doctorsId, 'assigned' => $doctorsId, 'pid' => $patientId, 'dsrc' => $dateSource ?: date('Y-m-d H:i:s'), 'visit_type' => $visitType]
            );
        } catch (Exception $e) {
            error_log('VisitDoctorSync::assignToVisit failed: ' . $e->getMessage());
        }
    }

    /** True once the migration adding the two columns has run. */
    private static function available(Database $db): bool {
        if (self::$available === null) {
            try {
                self::$available = (bool)$db->fetch("SHOW COLUMNS FROM visits LIKE 'checkup_doctor_id'");
            } catch (Exception $e) {
                self::$available = false;
            }
        }
        return self::$available;
    }
}
