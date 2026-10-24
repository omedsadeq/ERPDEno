/*
 * سەرچاوە مرۆییەکان (hr/) — tables.
 *
 * Brought over from the clinic's separate attendance system (Panjamor), which
 * kept everything in generic names (employees, shifts, holidays …). Here every
 * table is prefixed hr_ so nothing collides with the dental tables, and the
 * whole module can be removed by dropping hr_* and deleting hr/.
 *
 * hr_employees is one row per person who punches the fingerprint device —
 * doctors and staff alike. It is kept apart from users on purpose: a cleaner or
 * an assistant has no login, and users requires a username and a password.
 * hr_employees.user_id links a record to its login when there is one.
 *
 * hr_employees.id IS the person's PIN on the fingerprint device. Imported
 * punches rely on it, so a new employee takes their device PIN as id.
 *
 * Hours are never stored: they are worked out from hr_punches and
 * hr_adjustments every time (classes/HrAttendance.php), as Panjamor did, so a
 * corrected punch fixes every report at once.
 */

CREATE TABLE IF NOT EXISTS hr_departments (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- work_days: weekday numbers as PHP date('w') gives them (0 = Sunday … 6 =
-- Saturday), comma separated. A day not listed shows as پشوو in the reports.
CREATE TABLE IF NOT EXISTS hr_shifts (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    start_time      TIME         NOT NULL DEFAULT '08:00:00',
    end_time        TIME         NOT NULL DEFAULT '17:00:00',
    break_minutes   INT          NOT NULL DEFAULT 0,
    work_days       VARCHAR(20)  NOT NULL DEFAULT '6,0,1,2,3,4',
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- is_recurring: the same day every year (Newroz on 03-21), whatever the year
-- of holiday_date.
CREATE TABLE IF NOT EXISTS hr_holidays (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(150) NOT NULL,
    holiday_date  DATE         NOT NULL,
    is_recurring  TINYINT(1)   NOT NULL DEFAULT 0,
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_hr_holiday_date (holiday_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pay, exactly as Panjamor's monthly report reads it:
--   hourly_rate > 0           → hours × hourly_rate
--   otherwise basic_salary    → the fixed monthly salary
--   then + salary_percentage %, then rounded UP to 250 IQD.
CREATE TABLE IF NOT EXISTS hr_employees (
    id                 INT          NOT NULL PRIMARY KEY,
    full_name          VARCHAR(150) NOT NULL,
    employee_type      ENUM('staff','doctor') NOT NULL DEFAULT 'staff',
    position           VARCHAR(100) NULL,
    department_id      INT          NULL,
    shift_id           INT          NULL,
    user_id            INT          NULL,
    phone              VARCHAR(30)  NULL,
    national_id        VARCHAR(50)  NULL,
    gender             ENUM('male','female') NULL,
    birth_date         DATE         NULL,
    address            VARCHAR(255) NULL,
    emergency_contact  VARCHAR(150) NULL,
    emergency_phone    VARCHAR(30)  NULL,
    hire_date          DATE         NULL,
    end_date           DATE         NULL,
    basic_salary       DECIMAL(12,2) NULL,
    hourly_rate        DECIMAL(10,2) NULL,
    salary_percentage  DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    display_order      INT          NULL,
    status             ENUM('active','inactive') NOT NULL DEFAULT 'active',
    notes              TEXT         NULL,
    created_by         INT          NULL,
    created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_hr_employee_user (user_id),
    KEY idx_hr_employee_order (status, display_order),
    CONSTRAINT fk_hr_employee_department FOREIGN KEY (department_id) REFERENCES hr_departments(id) ON DELETE SET NULL,
    CONSTRAINT fk_hr_employee_shift      FOREIGN KEY (shift_id)      REFERENCES hr_shifts(id)      ON DELETE SET NULL,
    CONSTRAINT fk_hr_employee_user       FOREIGN KEY (user_id)       REFERENCES users(id)          ON DELETE SET NULL,
    CONSTRAINT fk_hr_employee_creator    FOREIGN KEY (created_by)    REFERENCES users(id)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per punch. The work day a punch belongs to is DATE(punch_time − 8h):
-- the clinic works past midnight, so a 01:30 punch-out closes the day before.
-- The unique key stops the same punch being saved twice.
CREATE TABLE IF NOT EXISTS hr_punches (
    id           BIGINT AUTO_INCREMENT PRIMARY KEY,
    employee_id  INT          NOT NULL,
    punch_time   DATETIME     NOT NULL,
    punch_type   ENUM('in','out') NOT NULL,
    source       ENUM('device','manual') NOT NULL DEFAULT 'manual',
    device_id    VARCHAR(50)  NULL,
    notes        VARCHAR(255) NULL,
    created_by   INT          NULL,
    updated_by   INT          NULL,
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_hr_punch (employee_id, punch_time),
    KEY idx_hr_punch_time (punch_time),
    CONSTRAINT fk_hr_punch_employee FOREIGN KEY (employee_id) REFERENCES hr_employees(id),
    CONSTRAINT fk_hr_punch_creator  FOREIGN KEY (created_by)  REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_hr_punch_updater  FOREIGN KEY (updated_by)  REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Hours added to (overtime) or taken from (deduction) one work day. They
-- change hours, not money: the money follows from the hours.
CREATE TABLE IF NOT EXISTS hr_adjustments (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    employee_id  INT          NOT NULL,
    adj_date     DATE         NOT NULL,
    adj_type     ENUM('overtime','deduction') NOT NULL,
    hours        DECIMAL(5,2) NOT NULL,
    reason       TEXT         NULL,
    created_by   INT          NULL,
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_hr_adj_employee_date (employee_id, adj_date),
    KEY idx_hr_adj_date (adj_date),
    CONSTRAINT fk_hr_adj_employee FOREIGN KEY (employee_id) REFERENCES hr_employees(id),
    CONSTRAINT fk_hr_adj_creator  FOREIGN KEY (created_by)  REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
