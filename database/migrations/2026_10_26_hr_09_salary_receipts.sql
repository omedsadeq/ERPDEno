/*
 * سەرچاوە مرۆییەکان — salary receipts, وەسڵی مووچەدان (hr/salary-receipt.php,
 * listed on hr/salary-receipts.php).
 *
 * One row per payment; its id is the receipt number. It keeps its own copy of
 * the figures, the person's name and PIN, and the person's electronic
 * signature (a JPEG data URL drawn on the screen, added when paid or later), so
 * a receipt reads the same for ever. Cancelling a payment keeps the receipt,
 * marked cancelled.
 *
 * as_expense: whether this payment was also written as a general expense
 * (expense_id). Chosen on each payment, on by default.
 */

CREATE TABLE IF NOT EXISTS hr_salary_receipts (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    payroll_id        INT NULL,
    employee_id       INT NOT NULL,
    pay_month         CHAR(7) NOT NULL,
    employee_name     VARCHAR(150) NOT NULL,
    employee_pin      VARCHAR(20) NULL,
    employee_type     VARCHAR(20) NULL,
    department_name   VARCHAR(150) NULL,
    net_hours         DECIMAL(10,4) NOT NULL DEFAULT 0,
    hourly_rate       DECIMAL(12,2) NOT NULL DEFAULT 0,
    basic_salary      DECIMAL(12,2) NOT NULL DEFAULT 0,
    salary_percentage DECIMAL(6,2) NOT NULL DEFAULT 0,
    calculated        DECIMAL(12,2) NOT NULL DEFAULT 0,
    bonus             DECIMAL(12,2) NOT NULL DEFAULT 0,
    deduction         DECIMAL(12,2) NOT NULL DEFAULT 0,
    amount            DECIMAL(12,2) NOT NULL DEFAULT 0,
    note              VARCHAR(255) NULL,
    paid_date         DATE NOT NULL,
    payment_method    ENUM('cash','transfer') NOT NULL DEFAULT 'cash',
    as_expense        TINYINT(1) NOT NULL DEFAULT 1,
    expense_id        INT NULL,
    signature         MEDIUMTEXT NULL,
    signed_at         DATETIME NULL,
    status            ENUM('valid','cancelled') NOT NULL DEFAULT 'valid',
    cancelled_by      INT NULL,
    cancelled_at      DATETIME NULL,
    created_by        INT NULL,
    created_at        TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_hr_receipts_month (pay_month, status),
    KEY idx_hr_receipts_employee (employee_id),
    CONSTRAINT fk_hr_receipts_employee FOREIGN KEY (employee_id) REFERENCES hr_employees (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE hr_payroll ADD COLUMN receipt_id INT NULL DEFAULT NULL AFTER expense_id;
