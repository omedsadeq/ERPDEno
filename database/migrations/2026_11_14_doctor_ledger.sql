-- حساباتی مانگانەی دکتۆر — a doctor's own monthly paper (admin/doctor-ledger.php):
-- what is left of his share, Lezan's, the rent, and his private expenses line by
-- line. Typed in by hand and tied to nothing else in the system; only the
-- doctor's name comes from users.
--
-- One sheet per doctor per month. Its lines: 'summary' (the top part, each
-- added or taken away by sign) and 'detail' (his private expenses, which are
-- taken away from the summary's total to leave «ماوە»).
CREATE TABLE IF NOT EXISTS doctor_ledger_sheets (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    doctor_id   INT          NOT NULL,
    month       CHAR(7)      NOT NULL,
    note        VARCHAR(500) NULL,
    created_by  INT          NULL,
    created_at  DATETIME     NULL,
    updated_by  INT          NULL,
    updated_at  DATETIME     NULL,
    UNIQUE KEY uq_doctor_ledger_month (doctor_id, month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS doctor_ledger_lines (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sheet_id    INT UNSIGNED  NOT NULL,
    section     VARCHAR(10)   NOT NULL DEFAULT 'detail',
    label       VARCHAR(255)  NOT NULL DEFAULT '',
    amount_iqd  DECIMAL(15,2) NOT NULL DEFAULT 0,
    amount_usd  DECIMAL(15,2) NOT NULL DEFAULT 0,
    sign        TINYINT       NOT NULL DEFAULT 1,
    note        VARCHAR(500)  NULL,
    sort_order  INT           NOT NULL DEFAULT 0,
    KEY idx_doctor_ledger_lines_sheet (sheet_id, section, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO permission_definitions (permission_key, permission_name, permission_name_ku, category, description) VALUES
('page.doctor_ledger', 'Doctor monthly sheet', 'حساباتی مانگانەی دکتۆر', 'admin', 'حساب و خەرجی تایبەتی مانگانەی دکتۆر، سەربەخۆ لە سیستەم');
