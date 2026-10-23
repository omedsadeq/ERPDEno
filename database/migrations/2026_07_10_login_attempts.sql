-- Migration: login attempt throttling (brute-force protection)
-- Date: 2026-07-10

CREATE TABLE IF NOT EXISTS login_attempts (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    identifier   VARCHAR(150) NOT NULL,       -- lowercased username + '|' + ip
    ip_address   VARCHAR(45)  NULL,
    attempted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_attempts_ident_time (identifier, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
