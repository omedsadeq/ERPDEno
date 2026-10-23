<?php
/**
 * AccessGuard — central doctor↔patient isolation guard.
 *
 * Policy (doctors only; admin and other staff roles are unaffected here and keep
 * their own page-level checks):
 *   - GENERAL info (demographics): a doctor may see a patient only if they OWN a
 *     folder for that patient OR reception referred the patient to them
 *     (appointment / waiting-room entry).
 *   - WORK (cases, folders, treatments, X-rays, finances): a doctor may see it
 *     ONLY for patients/cases they own. Referral never grants work access.
 *
 * Never trust patient_id / doctor_id / case_id posted from the browser: always
 * derive the acting doctor from the session and verify ownership server-side.
 */
class AccessGuard
{
    private static ?Patient $patient = null;
    private static ?Auth $auth = null;

    private static function patient(): Patient
    {
        if (self::$patient === null) {
            require_once __DIR__ . '/Patient.php';
            self::$patient = new Patient();
        }
        return self::$patient;
    }

    private static function auth(): Auth
    {
        if (self::$auth === null) {
            require_once __DIR__ . '/Auth.php';
            self::$auth = new Auth();
        }
        return self::$auth;
    }

    /** The logged-in user's id (0 if none). Same id space as patient_folders.doctor_id. */
    public static function doctorId(): int
    {
        return (int)($_SESSION['user_id'] ?? 0);
    }

    /**
     * True only for a plain doctor account that must be ownership-restricted.
     * Admins are never restricted; non-doctor roles are governed by their own logic.
     */
    public static function isRestrictedDoctor(): bool
    {
        $a = self::auth();
        return $a->isDoctor() && !$a->isAdmin();
    }

    // ── Patient-level ────────────────────────────────────────────────

    /** Doctor may view a patient's GENERAL info (owns OR reception-referred). */
    public static function doctorCanViewPatient(int $patientId): bool
    {
        return self::patient()->canDoctorViewPatientGeneral($patientId, self::doctorId());
    }

    /** Doctor may view/modify a patient's WORK data (owns a folder only). */
    public static function doctorCanViewPatientWork(int $patientId): bool
    {
        return self::patient()->isPatientOwnedByDoctor($patientId, self::doctorId());
    }

    /** Resolve the owning doctor + patient of a case (null if the case is unknown). */
    public static function caseContext(int $caseId): ?array
    {
        require_once __DIR__ . '/Database.php';
        $row = Database::getInstance()->fetch(
            "SELECT pf.doctor_id, pf.patient_id
             FROM cases c JOIN patient_folders pf ON c.folder_id = pf.id
             WHERE c.id = :id",
            ['id' => $caseId]
        );
        return $row ?: null;
    }

    /** Doctor owns this case (its folder is assigned to them). */
    public static function doctorCanViewCase(int $caseId): bool
    {
        $ctx = self::caseContext($caseId);
        return $ctx !== null && (int)$ctx['doctor_id'] === self::doctorId();
    }

    // ── Enforcement helpers (halt the request on failure) ────────────

    /** Require GENERAL patient access; no-op for non-restricted roles. */
    public static function requirePatientGeneral(int $patientId, bool $json = false): void
    {
        if (!self::isRestrictedDoctor()) return;
        if ($patientId <= 0 || !self::doctorCanViewPatient($patientId)) self::deny($json);
    }

    /** Require WORK (clinical/financial) patient access; no-op for non-restricted roles. */
    public static function requirePatientWork(int $patientId, bool $json = false): void
    {
        if (!self::isRestrictedDoctor()) return;
        if ($patientId <= 0 || !self::doctorCanViewPatientWork($patientId)) self::deny($json);
    }

    /** Require ownership of a case; no-op for non-restricted roles. */
    public static function requireCaseWork(int $caseId, bool $json = false): void
    {
        if (!self::isRestrictedDoctor()) return;
        if ($caseId <= 0 || !self::doctorCanViewCase($caseId)) self::deny($json);
    }

    private static function deny(bool $json): void
    {
        http_response_code(403);
        if ($json) {
            if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Access denied']);
        } else {
            if (!headers_sent()) header('Location: ' . APP_URL . '/403.php');
            echo 'Access denied';
        }
        exit;
    }
}
