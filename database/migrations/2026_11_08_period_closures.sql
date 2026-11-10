-- داخستنی مانگ — a month of the accounts, closed once they are done
-- (accountant/month-close.php, classes/PeriodClose.php).
--
-- While a month stands closed, nothing dated in it that the income sheet counts
-- may be added, changed or deleted: receipts, payments, installments, x-ray
-- payments, pharmacy sales and returns, the examination fee, the sheet's own
-- entries and columns, every expense. Database triggers hold the line for
-- every screen (installed by PeriodClose::installGuards when a month is
-- closed). The admin may reopen it with a reason. Each closing keeps a frozen
-- copy of the sheet as it stood (snapshot, JSON).
--
-- One row per closing: status closed, or reopened once it was opened again.
-- Closing it again adds a new row with a new copy, so the history stays.
CREATE TABLE IF NOT EXISTS period_closures (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    month          CHAR(7)      NOT NULL,
    status         VARCHAR(10)  NOT NULL DEFAULT 'closed',
    snapshot       LONGTEXT     NULL,
    note           VARCHAR(255) NULL,
    closed_by      INT          NULL,
    closed_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reopened_by    INT          NULL,
    reopened_at    DATETIME     NULL,
    reopen_reason  VARCHAR(255) NULL,
    KEY idx_period_closures_month (month, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Closing moves nothing but stops every hand: the admin only, until given.
INSERT IGNORE INTO permission_definitions (permission_key, permission_name, permission_name_ku, category, description)
VALUES ('income.month_close', 'Close a month of the accounts', 'داخستنی مانگی حیسابات', 'financial',
        'Close a month once its accounts are done: nothing dated in it can be added, changed or deleted until it is reopened.');
INSERT IGNORE INTO role_permissions (role, permission_key, is_allowed) VALUES
('accountant', 'income.month_close', 0), ('doctor', 'income.month_close', 0),
('receptionist', 'income.month_close', 0), ('xray_department', 'income.month_close', 0);
