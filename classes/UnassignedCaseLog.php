<?php
/**
 * UnassignedCaseLog — audit trail for the transition-phase access switch
 * تۆماری کەیسە ئازادەکان
 *
 * While `allow_unassigned_patient_cases` is ON a doctor can start a case for a
 * patient who was never referred to them. That is a deliberate loosening for
 * the data-organisation phase, so every use of it is recorded here (silently —
 * a logging failure must never block clinical work).
 *
 * Read it back on admin/unassigned-cases.php to answer "how many patients came
 * in this way, and through whom?" before switching the flag OFF.
 */

require_once __DIR__ . '/Database.php';

class UnassignedCaseLog
{
    /** Case opened for a patient nobody owned yet. */
    const ACTION_CASE_UNASSIGNED = 'create_case_unassigned';

    /** Case opened for a patient who already had folders with another doctor. */
    const ACTION_CASE_OTHER_DOCTOR = 'create_case_other_doctor';

    /**
     * Record one event. Never throws.
     *
     * @param string $relation doctorPatientRelation() value at the moment the
     *                         case was created ('unassigned' | 'other_doctor' |
     *                         'referred'), i.e. how the doctor reached the patient.
     */
    public static function record(
        int $doctorId,
        int $patientId,
        string $actionType,
        string $relation,
        ?int $caseId = null,
        ?int $folderId = null
    ): void {
        try {
            Database::getInstance()->insert('unassigned_case_log', [
                'doctor_id'        => $doctorId,
                'patient_id'       => $patientId,
                'case_id'          => $caseId,
                'folder_id'        => $folderId,
                'action_type'      => mb_substr($actionType, 0, 40),
                'patient_relation' => mb_substr($relation, 0, 20),
                'ip_address'       => $_SERVER['REMOTE_ADDR'] ?? null,
                'created_at'       => date('Y-m-d H:i:s'),
            ]);
        } catch (Exception $e) {
            error_log('[UnassignedCaseLog] ' . $e->getMessage());
        }
    }

    /**
     * Log a case creation when — and only when — the doctor did not already own
     * the patient. Called from the case-creation paths; decides for itself
     * whether the event is worth recording.
     */
    public static function recordCaseCreation(
        int $doctorId,
        int $patientId,
        string $relation,
        ?int $caseId,
        ?int $folderId
    ): void {
        if ($relation === 'own') {
            return; // ordinary work on the doctor's own patient
        }
        $action = $relation === 'other_doctor'
            ? self::ACTION_CASE_OTHER_DOCTOR
            : self::ACTION_CASE_UNASSIGNED;
        self::record($doctorId, $patientId, $action, $relation, $caseId, $folderId);
    }

    /** Total rows, honouring the same filters as recent(). */
    public static function count(array $filters = []): int
    {
        [$where, $params] = self::buildWhere($filters);
        try {
            // Alias `l` is required: buildWhere() qualifies every column with it.
            $row = Database::getInstance()->fetch(
                "SELECT COUNT(*) AS c FROM unassigned_case_log l {$where}", $params
            );
            return (int)($row['c'] ?? 0);
        } catch (Exception $e) {
            error_log('[UnassignedCaseLog] count: ' . $e->getMessage());
            return 0;
        }
    }

    /** One page of the log, newest first, joined with doctor + patient names. */
    public static function recent(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = self::buildWhere($filters);
        $limit  = max(1, min(200, $limit));
        $offset = max(0, $offset);
        try {
            return Database::getInstance()->fetchAll(
                "SELECT l.*, u.full_name AS doctor_name,
                        p.full_name AS patient_name, p.patient_code
                 FROM unassigned_case_log l
                 LEFT JOIN users u ON u.id = l.doctor_id
                 LEFT JOIN patients p ON p.id = l.patient_id
                 {$where}
                 ORDER BY l.id DESC
                 LIMIT {$limit} OFFSET {$offset}",
                $params
            );
        } catch (Exception $e) {
            error_log('[UnassignedCaseLog] read: ' . $e->getMessage());
            return [];
        }
    }

    /** Per-doctor totals for the report header. */
    public static function byDoctor(array $filters = []): array
    {
        [$where, $params] = self::buildWhere($filters);
        try {
            return Database::getInstance()->fetchAll(
                "SELECT l.doctor_id, u.full_name AS doctor_name,
                        COUNT(*) AS total,
                        COUNT(DISTINCT l.patient_id) AS patients
                 FROM unassigned_case_log l
                 LEFT JOIN users u ON u.id = l.doctor_id
                 {$where}
                 GROUP BY l.doctor_id, u.full_name
                 ORDER BY total DESC",
                $params
            );
        } catch (Exception $e) {
            error_log('[UnassignedCaseLog] read: ' . $e->getMessage());
            return [];
        }
    }

    /** Build the shared WHERE clause. Filters: doctor_id, action_type, date_from, date_to. */
    private static function buildWhere(array $filters): array
    {
        $sql = ' WHERE 1=1';
        $params = [];
        if (!empty($filters['doctor_id'])) {
            $sql .= ' AND l.doctor_id = :doctor_id';
            $params['doctor_id'] = (int)$filters['doctor_id'];
        }
        if (!empty($filters['action_type'])) {
            $sql .= ' AND l.action_type = :action_type';
            $params['action_type'] = (string)$filters['action_type'];
        }
        if (!empty($filters['date_from'])) {
            $sql .= ' AND DATE(l.created_at) >= :date_from';
            $params['date_from'] = (string)$filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $sql .= ' AND DATE(l.created_at) <= :date_to';
            $params['date_to'] = (string)$filters['date_to'];
        }
        return [$sql, $params];
    }
}
