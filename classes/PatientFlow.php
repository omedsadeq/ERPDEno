<?php
/**
 * Patient Flow — single tracker of where a patient is right now.
 * سیستەمی زیرەکی کلینیکی ددان
 *
 * The current stage lives on waiting_room.status
 * (waiting -> in_progress -> at_accounting / at_xray -> in_progress -> completed).
 * Every handoff is written to visit_flow_log with the acting user.
 *
 * All methods fail soft (return false) so callers like the x-ray workflow
 * keep working even when the 2026_07_10_patient_flow_stages migration has
 * not been applied on the server yet.
 */

require_once __DIR__ . '/Database.php';

class PatientFlow {
    /** Statuses that mean the patient is still inside the clinic */
    public const ACTIVE_STATUSES = ['waiting', 'called', 'in_treatment', 'in_progress', 'on_hold', 'at_accounting', 'at_xray'];

    /** Stations a patient can be sent to and brought back from */
    public const STATION_STATUSES = ['at_accounting', 'at_xray'];

    /**
     * Today's active waiting-room entry for a patient, or null.
     */
    public static function findActiveEntry(int $patientId): ?array {
        if ($patientId <= 0) {
            return null;
        }
        try {
            $db = Database::getInstance();
            $placeholders = "'" . implode("','", self::ACTIVE_STATUSES) . "'";
            $row = $db->fetch(
                "SELECT * FROM waiting_room
                 WHERE patient_id = :patient_id
                   AND status IN ({$placeholders})
                   AND DATE(check_in_time) = CURDATE()
                 ORDER BY id DESC
                 LIMIT 1",
                ['patient_id' => $patientId]
            );
            return $row ?: null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Move a waiting-room entry to a new stage and log the handoff.
     */
    public static function move(array $entry, string $toStatus, ?int $userId, string $action, string $note = ''): bool {
        $waitingId = (int)($entry['id'] ?? 0);
        if ($waitingId <= 0) {
            return false;
        }

        try {
            $db = Database::getInstance();
            $data = [
                'status' => $toStatus,
                'updated_by' => $userId
            ];
            if ($toStatus === 'completed') {
                $data['check_out_time'] = date('Y-m-d H:i:s');
            }
            $db->update('waiting_room', $data, 'id = :id', ['id' => $waitingId]);
        } catch (Exception $e) {
            return false;
        }

        self::record($waitingId, (int)($entry['patient_id'] ?? 0), $entry['status'] ?? null, $toStatus, $userId, $action, $note);

        if (class_exists('AuditLog')) {
            try {
                AuditLog::log('waiting_' . $toStatus, 'waiting_room', $waitingId,
                    ['status' => $entry['status'] ?? null],
                    ['status' => $toStatus, 'action' => $action, 'note' => $note]);
            } catch (Exception $e) {
                // Flow log above is the primary trail; audit log is best-effort.
            }
        }

        return true;
    }

    /**
     * Move a patient's active entry to a new stage.
     * $onlyFrom limits which current stages may transition (null = any active stage).
     */
    public static function moveByPatient(int $patientId, string $toStatus, ?int $userId, string $action, string $note = '', ?array $onlyFrom = null): bool {
        $entry = self::findActiveEntry($patientId);
        if (!$entry) {
            return false;
        }
        if (($entry['status'] ?? '') === $toStatus) {
            return false;
        }
        if ($onlyFrom !== null && !in_array($entry['status'] ?? '', $onlyFrom, true)) {
            return false;
        }
        return self::move($entry, $toStatus, $userId, $action, $note);
    }

    /**
     * True when the given user id is a doctor account.
     */
    public static function isDoctor(int $userId): bool {
        if ($userId <= 0) {
            return false;
        }
        try {
            $row = Database::getInstance()->fetch(
                "SELECT role FROM users WHERE id = :id",
                ['id' => $userId]
            );
            return ($row['role'] ?? '') === 'doctor';
        } catch (Exception $e) {
            return true; // fail open: keep normal back-to-doctor behavior
        }
    }

    /**
     * Write one handoff row to visit_flow_log (silent if table is missing).
     */
    public static function record(?int $waitingRoomId, int $patientId, ?string $fromStatus, string $toStatus, ?int $userId, string $action, string $note = ''): void {
        if ($patientId <= 0) {
            return;
        }
        try {
            Database::getInstance()->insert('visit_flow_log', [
                'waiting_room_id' => $waitingRoomId ?: null,
                'patient_id' => $patientId,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'action' => $action,
                'note' => mb_substr($note, 0, 500),
                'moved_by' => $userId
            ]);
        } catch (Exception $e) {
            // Gracefully fail if migration hasn't been executed yet
        }
    }

    /**
     * Register a patient in the waiting room directly at a station stage.
     * Used when a doctor sends someone to accounting / x-ray who has no
     * check-in today: per the clinic workflow the patient must always be
     * queued in the waiting room and called in turn — never walk straight in.
     */
    public static function createStationEntry(int $patientId, string $toStatus, ?int $userId, ?int $doctorUserId = null, string $note = ''): ?int {
        if ($patientId <= 0 || !in_array($toStatus, self::STATION_STATUSES, true)) {
            return null;
        }
        try {
            $db = Database::getInstance();

            // The entry's doctor = the requesting doctor (so the patient can be
            // returned to them later); fall back to the acting user if they are
            // a doctor themselves.
            $entryDoctor = null;
            if ($doctorUserId && self::isDoctor($doctorUserId)) {
                $entryDoctor = $doctorUserId;
            } elseif ($userId && self::isDoctor($userId)) {
                $entryDoctor = $userId;
            }

            $wid = $db->insert('waiting_room', [
                'patient_id' => $patientId,
                'doctor_id' => $entryDoctor,
                'check_in_time' => date('Y-m-d H:i:s'),
                'status' => $toStatus,
                'notes' => mb_substr(trim($note), 0, 250),
                'created_by' => $userId
            ]);
            self::record((int)$wid, $patientId, null, $toStatus, $userId, 'station_check_in', $note);

            if (class_exists('AuditLog')) {
                try {
                    AuditLog::log('waiting_' . $toStatus, 'waiting_room', (int)$wid, null, [
                        'patient_id' => $patientId,
                        'source' => 'station_check_in'
                    ]);
                } catch (Exception $e) {
                    // Flow log above is the primary trail; audit log is best-effort.
                }
            }
            return (int)$wid;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Mirror an x-ray workflow transition onto the patient's queue position:
     *   pending_accountant / returned_to_accountant -> at_accounting
     *   pending_xray / in_xray                      -> at_xray
     *   completed / cancelled                       -> back to the doctor (in_progress)
     *
     * $doctorUserId = the requesting doctor (xray_requests.requested_by), used
     * when the patient has no waiting-room entry today and one must be created.
     */
    public static function syncFromXray(int $patientId, string $requestStatus, ?int $userId, string $note = '', ?int $doctorUserId = null): void {
        $map = [
            'pending_accountant'     => 'at_accounting',
            'returned_to_accountant' => 'at_accounting',
            'pending_xray'           => 'at_xray',
            'in_xray'                => 'at_xray',
            'completed'              => 'in_progress',
            'cancelled'              => 'in_progress'
        ];
        if (!isset($map[$requestStatus])) {
            return;
        }

        $entry = self::findActiveEntry($patientId);
        if (!$entry) {
            // Not checked in today: an x-ray/accounting send must still queue
            // the patient in the waiting room (finish transitions need no entry)
            if (in_array($map[$requestStatus], self::STATION_STATUSES, true)) {
                self::createStationEntry($patientId, $map[$requestStatus], $userId, $doctorUserId, $note);
            }
            return;
        }

        $toStatus = $map[$requestStatus];
        if ($toStatus === 'in_progress') {
            // Only pull the patient back to the doctor from a station stage —
            // never touch a patient who is still waiting/on hold.
            if (!in_array($entry['status'] ?? '', self::STATION_STATUSES, true)) {
                return;
            }
            // X-ray-only visit (assigned to the x-ray account or nobody):
            // a finished scan ends the visit — there is no doctor to return to.
            if (empty($entry['doctor_id']) || !self::isDoctor((int)$entry['doctor_id'])) {
                $toStatus = 'completed';
            }
        }

        if (($entry['status'] ?? '') === $toStatus) {
            return;
        }
        self::move($entry, $toStatus, $userId, 'xray_' . $requestStatus, $note);
    }
}
