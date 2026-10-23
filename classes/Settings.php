<?php
/**
 * Settings - typed reader/writer for the `settings` key/value table
 * ڕێکخستنەکانی سیستەم
 *
 * The table is a flat key/value store (setting_key, setting_value,
 * setting_type, description). Pages used to query it ad-hoc; this class gives
 * one cached entry point so a setting can be read cheaply from anywhere,
 * including hot paths like the doctor↔patient access checks that run on every
 * patient page load.
 *
 * All values are cached per-request after the first read; set()/invalidate()
 * keep the cache honest. A missing `settings` table (fresh install, restore in
 * progress) degrades to the caller's default instead of throwing.
 */

require_once __DIR__ . '/Database.php';

class Settings
{
    /** Doctors may open/create cases for patients not assigned to them. */
    const ALLOW_UNASSIGNED_PATIENT_CASES = 'allow_unassigned_patient_cases';

    /**
     * Admins may still edit a visit invoice AFTER it was sent to the accountant.
     * OFF by default: a sent receipt is a document the accountant is working
     * from, so its treatments and its discount are frozen for everyone.
     */
    const ALLOW_EDIT_SENT_INVOICE = 'allow_edit_sent_invoice';

    /**
     * Accountant/admin may correct an APPROVED invoice in place — discount,
     * received figure, cash/debt/instalment, promise, accounting note — after
     * pressing «تەعدیل» on it in the Approval Center (it always opens locked),
     * while it STAYS approved with its original approval date (CaseInvoice::
     * editApproved), or cancel it outright with its money reversed
     * (classes/ApprovalReversal.php). OFF by default: an approved receipt is a
     * booked document, and changing one changes reported figures.
     */
    const ALLOW_EDIT_APPROVED_INVOICE = 'allow_edit_approved_invoice';

    /**
     * Waiting room «بانگکردن»: OFF (default) — every doctor may call their own
     * patients in. ON — only the doctors listed in WAITING_CALL_DOCTOR_IDS may;
     * for the rest reception/admin calls the patient.
     */
    const WAITING_CALL_RESTRICTED = 'waiting_call_restricted';

    /** Comma-separated users.id of the doctors allowed to call while restricted. */
    const WAITING_CALL_DOCTOR_IDS = 'waiting_call_doctor_ids';

    /** @var array<string,string>|null all rows, loaded once per request */
    private static $cache = null;

    /** Load the whole table once — it is tiny (dozens of rows). */
    private static function load(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            try {
                $rows = Database::getInstance()->fetchAll(
                    "SELECT setting_key, setting_value FROM settings"
                );
                foreach ($rows as $row) {
                    self::$cache[$row['setting_key']] = (string)$row['setting_value'];
                }
            } catch (Throwable $e) {
                // Table missing / DB restoring — callers fall back to defaults.
                // Caught as Throwable because this runs on every patient page:
                // a settings problem must never fatal the app.
                error_log('[Settings] load failed: ' . $e->getMessage());
            }
        }
        return self::$cache;
    }

    /** Drop the per-request cache (after a write from another code path). */
    public static function invalidate(): void
    {
        self::$cache = null;
    }

    /** Raw string value, or $default when the key is absent. */
    public static function get(string $key, ?string $default = null): ?string
    {
        $all = self::load();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    /**
     * Boolean value. '1', 'true', 'yes', 'on' are true; everything else false.
     * $default applies only when the key does not exist at all.
     */
    public static function getBool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            return $default;
        }
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function getInt(string $key, int $default = 0): int
    {
        $value = self::get($key);
        return $value === null || $value === '' ? $default : (int)$value;
    }

    /** Insert or update one setting; keeps the request cache in sync. */
    public static function set(string $key, string $value, string $type = 'text', ?string $description = null): void
    {
        $db = Database::getInstance();
        $existing = $db->fetch("SELECT id FROM settings WHERE setting_key = :k", ['k' => $key]);
        if ($existing) {
            $data = ['setting_value' => $value];
            if ($description !== null) {
                $data['description'] = $description;
            }
            $db->update('settings', $data, 'setting_key = :k', ['k' => $key]);
        } else {
            $db->insert('settings', [
                'setting_key'   => $key,
                'setting_value' => $value,
                'setting_type'  => $type,
                'description'   => $description,
            ]);
        }
        self::load();
        self::$cache[$key] = $value;
    }

    // ── Named policy accessors ────────────────────────────────────────

    /**
     * Transition switch (default ON).
     *
     * ON  — a doctor may look up ANY patient in the clinic (demographics only)
     *       and start a case for a patient nobody has assigned to them. Needed
     *       while historical data is still being organised.
     * OFF — a doctor only reaches patients they own, that reception referred to
     *       them (appointment / waiting-room), or that they registered.
     *
     * It NEVER affects WORK access: another doctor's cases, treatments, X-rays
     * and finances stay invisible in both modes.
     */
    public static function allowUnassignedPatientCases(): bool
    {
        return self::getBool(self::ALLOW_UNASSIGNED_PATIENT_CASES, true);
    }

    /**
     * Whether a sent invoice can still be edited (admin only, and only while
     * this is ON). Default OFF — sending is meant to be final.
     */
    public static function allowEditSentInvoice(): bool
    {
        return self::getBool(self::ALLOW_EDIT_SENT_INVOICE, false);
    }

    /**
     * Whether an approved invoice can be corrected in place, or cancelled with
     * its money reversed, by the accountant or an admin
     * (CaseInvoice::editApproved / ApprovalReversal, surfaced in the Approval
     * Center's invoice window and its پەسەندکراو tab). Default OFF — approval
     * is meant to be final.
     *
     * An in-place edit never touches the approval itself: the invoice stays
     * approved, same approver, same approval date. The full case editor also
     * uses this setting for treatment corrections, with paid-balance and
     * active installment-plan checks before saving.
     */
    public static function allowEditApprovedInvoice(): bool
    {
        return self::getBool(self::ALLOW_EDIT_APPROVED_INVOICE, false);
    }

    /** Whether only the chosen doctors may call patients in from the waiting room. */
    public static function waitingCallRestricted(): bool
    {
        return self::getBool(self::WAITING_CALL_RESTRICTED, false);
    }

    /** @return int[] doctors allowed to call while the restriction is ON */
    public static function waitingCallDoctorIds(): array
    {
        $ids = array_map('intval', explode(',', (string)self::get(self::WAITING_CALL_DOCTOR_IDS, '')));
        return array_values(array_unique(array_filter($ids, static fn($id) => $id > 0)));
    }

    /**
     * May this doctor call their own waiting patients in (waiting-room.php
     * `call`)? Always true while the restriction is OFF. Admin and reception
     * are never affected — callers only ask this for the doctor role.
     */
    public static function doctorMayCallPatients(int $doctorUserId): bool
    {
        return !self::waitingCallRestricted()
            || in_array($doctorUserId, self::waitingCallDoctorIds(), true);
    }
}
