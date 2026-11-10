-- داتای پێشوو — the income paper of a month kept in another system (2025, the
-- clinic's old system), typed or pasted in so «بەراورد» can set this year
-- against it (accountant/income-archive.php, classes/IncomeArchive.php).
--
-- One row per month (its expenses and other deductions such as salaries
-- paid outside the expenses), and one row per line of its paper: a doctor of
-- this system (row_key d<users.id>, so it lines up with the doctor's row
-- now), X-ray, Pharmacy, Examinations, Other, or a line of its own (x…, a
-- doctor who is not in this system). The figures are the paper's: income,
-- lab, store and implant with the doctor's part, what is left, the split and
-- the two shares, the hours (minutes), days and examinations.
CREATE TABLE IF NOT EXISTS income_archive_months (
    month       CHAR(7)       NOT NULL PRIMARY KEY,
    expenses    DECIMAL(15,2) NOT NULL DEFAULT 0,
    deductions  DECIMAL(15,2) NOT NULL DEFAULT 0,
    note        VARCHAR(255)  NULL,
    updated_by  INT           NULL,
    updated_at  DATETIME      NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS income_archive_rows (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    month              CHAR(7)       NOT NULL,
    row_key            VARCHAR(20)   NOT NULL,
    kind               VARCHAR(10)   NOT NULL DEFAULT 'doctor',
    label              VARCHAR(80)   NOT NULL,
    sort_order         INT           NOT NULL DEFAULT 0,
    amount             DECIMAL(15,2) NOT NULL DEFAULT 0,
    lab                DECIMAL(15,2) NOT NULL DEFAULT 0,
    store_normal       DECIMAL(15,2) NOT NULL DEFAULT 0,
    store_normal_doc   DECIMAL(15,2) NOT NULL DEFAULT 0,
    store_implant      DECIMAL(15,2) NOT NULL DEFAULT 0,
    store_implant_doc  DECIMAL(15,2) NOT NULL DEFAULT 0,
    net                DECIMAL(15,2) NOT NULL DEFAULT 0,
    rate               DECIMAL(5,2)  NULL,
    doctor_share       DECIMAL(15,2) NOT NULL DEFAULT 0,
    center_share       DECIMAL(15,2) NOT NULL DEFAULT 0,
    minutes            INT           NULL,
    days               INT           NOT NULL DEFAULT 0,
    pending            INT           NOT NULL DEFAULT 0,
    given              INT           NOT NULL DEFAULT 0,
    old_exam           INT           NOT NULL DEFAULT 0,
    new_exam           INT           NOT NULL DEFAULT 0,
    cases_given        INT           NOT NULL DEFAULT 0,
    UNIQUE KEY uq_income_archive_row (month, row_key),
    KEY idx_income_archive_month (month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Typing in another system's figures: the admin, until given.
INSERT IGNORE INTO permission_definitions (permission_key, permission_name, permission_name_ku, category, description)
VALUES ('income.archive', 'Earlier income data', 'داتای پێشووی داهات (بۆ بەراورد)', 'financial',
        'Type in or paste a month''s income paper from the old system (2025) so the comparison can set this year against it.');
INSERT IGNORE INTO role_permissions (role, permission_key, is_allowed) VALUES
('accountant', 'income.archive', 0), ('doctor', 'income.archive', 0),
('receptionist', 'income.archive', 0), ('xray_department', 'income.archive', 0);
