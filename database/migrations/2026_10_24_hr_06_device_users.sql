/*
 * سەرچاوە مرۆییەکان — who is on the fingerprint device, and which employee
 * each device PIN belongs to.
 *
 * hr_employees.device_pin: the person's PIN ON THE DEVICE. Until now an
 * employee's id was that PIN; it still is for everyone already here (filled
 * in below), but a person enrolled on the device under another number can now
 * be linked to their existing employee without renumbering anything — set
 * device_pin and their punches follow. fingerprint-sync/zk_sync.py finds the
 * employee by device_pin (falling back to id when it is empty).
 *
 * hr_device_users: the device's own user list (PIN + the name typed on the
 * device), copied by the sync service. hr/device-users.php compares it with
 * the employees: people on the device but not in Lezan can be added with the
 * device's name in one click, and a device PIN can be linked to an employee.
 */

ALTER TABLE hr_employees ADD COLUMN device_pin VARCHAR(20) NULL AFTER id;
UPDATE hr_employees SET device_pin = CAST(id AS CHAR) WHERE device_pin IS NULL;
ALTER TABLE hr_employees ADD UNIQUE KEY uq_hr_employee_device_pin (device_pin);

CREATE TABLE IF NOT EXISTS hr_device_users (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    device_ip      VARCHAR(45)  NOT NULL,
    device_serial  VARCHAR(50)  NULL,
    pin            VARCHAR(20)  NOT NULL,
    name           VARCHAR(100) NULL,
    uid            INT          NULL,
    privilege      INT          NULL,
    card           VARCHAR(30)  NULL,
    first_seen_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_hr_device_user (device_ip, pin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
