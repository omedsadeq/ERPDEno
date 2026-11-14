-- بەراورد — a copy of the comparison the accountant edits by hand (numbers,
-- names, rows) to present it differently. Only the copy changes: the live
-- report keeps reading the real figures. The tables are kept as plain text
-- (JSON: titles, headings, rows of cells), never as HTML (classes/IncomeCompareCopy.php).

CREATE TABLE IF NOT EXISTS income_compare_copies (
    id          INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    title       VARCHAR(150) NOT NULL,
    a_from      DATE NULL,
    a_to        DATE NULL,
    b_from      DATE NULL,
    b_to        DATE NULL,
    avg         TINYINT(1) NOT NULL DEFAULT 0,
    content     MEDIUMTEXT NOT NULL,
    created_by  INT NULL,
    updated_by  INT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_icc_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
