<?php
/**
 * DoctorSync - keeps the `doctors` clinical directory in sync with `users`
 * سیستەمی زیرەکی کلینیکی ددان
 *
 * The new-visit page (and visit reports) list doctors from the `doctors`
 * table, while login/appointments/waiting-room use `users`. Historically the
 * two lists were maintained by hand and drifted apart, so newly added doctor
 * accounts never appeared on the new-visit page.
 *
 * Call DoctorSync::syncFromUser($userId) after ANY create / update /
 * deactivate of a user:
 *   - role = doctor  → creates or updates the linked doctors row
 *                      (doctors.user_id = users.id, name/specialization/
 *                       is_active copied from the account)
 *   - role ≠ doctor  → deactivates a previously linked doctors row
 *
 * Existing legacy rows with the same name are adopted (linked) instead of
 * duplicated. Rows are never deleted — old visits keep their doctor ids.
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/AuditLog.php';

class DoctorSync {

    /**
     * Sync the doctors directory for one user account.
     * Returns the doctors.id linked to this user, or null.
     */
    public static function syncFromUser($userId) {
        $db = Database::getInstance();

        $user = $db->fetch(
            "SELECT id, full_name, role, is_active, specialization FROM users WHERE id = :id",
            ['id' => (int)$userId]
        );
        if (!$user) {
            return null;
        }

        $linked = $db->fetch("SELECT * FROM doctors WHERE user_id = :uid LIMIT 1", ['uid' => (int)$userId]);

        if ($user['role'] === 'doctor') {
            $syncData = [
                'name'           => $user['full_name'],
                'specialization' => $user['specialization'] ?? '',
                'is_active'      => (int)$user['is_active'],
            ];

            if ($linked) {
                // Keep name / specialization / active flag in sync with the account
                $changed = $syncData['name'] !== $linked['name']
                    || (string)$syncData['specialization'] !== (string)($linked['specialization'] ?? '')
                    || (int)$syncData['is_active'] !== (int)$linked['is_active'];
                if ($changed) {
                    $db->update('doctors', $syncData, 'id = :id', ['id' => $linked['id']]);
                    AuditLog::logChange('sync_doctor', 'doctors', $linked['id'], $linked, $syncData);
                }
                return (int)$linked['id'];
            }

            // Adopt a legacy row with the exact same name instead of duplicating it
            $sameName = $db->fetch(
                "SELECT id FROM doctors WHERE user_id IS NULL AND name = :name LIMIT 1",
                ['name' => $user['full_name']]
            );
            if ($sameName) {
                $db->update('doctors',
                    array_merge($syncData, ['user_id' => (int)$userId]),
                    'id = :id', ['id' => $sameName['id']]);
                AuditLog::log('link_doctor_user', 'doctors', $sameName['id'], null,
                    ['user_id' => (int)$userId, 'name' => $user['full_name']]);
                return (int)$sameName['id'];
            }

            // No row yet → create it so the doctor appears on the new-visit page
            $newId = $db->insert('doctors', array_merge($syncData, ['user_id' => (int)$userId]));
            AuditLog::log('create_doctor_from_user', 'doctors', $newId, null,
                ['user_id' => (int)$userId, 'name' => $user['full_name']]);
            return (int)$newId;
        }

        // Account is not (or no longer) a doctor → hide its clinical record
        if ($linked && (int)$linked['is_active'] === 1) {
            $db->update('doctors', ['is_active' => 0], 'id = :id', ['id' => $linked['id']]);
            AuditLog::log('deactivate_doctor', 'doctors', $linked['id'],
                ['is_active' => 1], ['is_active' => 0, 'reason' => 'user role/status change']);
        }
        return $linked ? (int)$linked['id'] : null;
    }

    /**
     * One-time / maintenance backfill: make sure every doctor account has a
     * doctors row. Returns the number of rows created or linked.
     */
    public static function backfillAll() {
        $db = Database::getInstance();
        $doctorUsers = $db->fetchAll("SELECT id FROM users WHERE role = 'doctor'");
        $count = 0;
        foreach ($doctorUsers as $u) {
            $before = $db->fetch("SELECT id FROM doctors WHERE user_id = :uid", ['uid' => $u['id']]);
            self::syncFromUser($u['id']);
            $after = $db->fetch("SELECT id FROM doctors WHERE user_id = :uid", ['uid' => $u['id']]);
            if (!$before && $after) {
                $count++;
            }
        }
        return $count;
    }
}
