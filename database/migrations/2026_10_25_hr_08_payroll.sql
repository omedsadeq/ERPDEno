/*
 * سەرچاوە مرۆییەکان — paying the monthly salaries (hr/payroll.php).
 *
 * hr_payroll: one row per employee and month, written when the month's pay is
 * approved. It keeps the figures as they were then (hours, the rate, basic
 * salary and percentage, the calculated pay, bonus, deduction), so a punch
 * corrected afterwards cannot change a salary that was already decided or
 * paid: the page shows the difference instead. Paying it writes one row in
 * expenses_general (category «مووچە») and keeps its id here.
 *
 * expenses_general.source_type / source_id: that expense row belongs to the
 * payroll row ('hr_payroll', hr_payroll.id). admin/expenses-general.php does
 * not edit or delete such rows; cancelling the payment does.
 *
 * hr.payroll.pay: approve and pay. Admin always has it; nobody else by default.
 */

CREATE TABLE IF NOT EXISTS hr_payroll (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    employee_id       INT NOT NULL,
    pay_month         CHAR(7) NOT NULL,
    net_hours         DECIMAL(10,4) NOT NULL DEFAULT 0,
    hourly_rate       DECIMAL(12,2) NOT NULL DEFAULT 0,
    basic_salary      DECIMAL(12,2) NOT NULL DEFAULT 0,
    salary_percentage DECIMAL(6,2) NOT NULL DEFAULT 0,
    calculated        DECIMAL(12,2) NOT NULL DEFAULT 0,
    bonus             DECIMAL(12,2) NOT NULL DEFAULT 0,
    deduction         DECIMAL(12,2) NOT NULL DEFAULT 0,
    amount            DECIMAL(12,2) NOT NULL DEFAULT 0,
    note              VARCHAR(255) NULL,
    status            ENUM('approved','paid') NOT NULL DEFAULT 'approved',
    approved_by       INT NULL,
    approved_at       DATETIME NULL,
    paid_date         DATE NULL,
    payment_method    ENUM('cash','transfer') NULL,
    paid_by           INT NULL,
    paid_at           DATETIME NULL,
    expense_id        INT NULL,
    created_at        TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_hr_payroll_month (employee_id, pay_month),
    KEY idx_hr_payroll_status (pay_month, status),
    CONSTRAINT fk_hr_payroll_employee FOREIGN KEY (employee_id) REFERENCES hr_employees (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE expenses_general ADD COLUMN source_type VARCHAR(30) NULL DEFAULT NULL AFTER attachment;
ALTER TABLE expenses_general ADD COLUMN source_id INT NULL DEFAULT NULL AFTER source_type;
ALTER TABLE expenses_general ADD INDEX idx_expenses_general_source (source_type, source_id);

INSERT IGNORE INTO permission_definitions (permission_key, permission_name, permission_name_ku, category, description) VALUES
    ('hr.payroll.pay', 'Pay Salaries', 'پەسەندکردن و پێدانی مووچە', 'hr', 'Approve the month''s salaries and pay them (each payment is a general expense)');

INSERT IGNORE INTO role_permissions (role, permission_key, is_allowed) VALUES
    ('accountant',      'hr.payroll.pay', 0),
    ('receptionist',    'hr.payroll.pay', 0),
    ('doctor',          'hr.payroll.pay', 0),
    ('xray_department', 'hr.payroll.pay', 0);
