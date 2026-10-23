-- ============================================================
-- Notifications v2 — unify schema across installs
-- Safe/idempotent: MigrationRunner ignores duplicate column/key/table errors.
-- Brings any install (old ENUM-type table or fresh) up to the modern shape:
--   varchar type, data JSON, link, is_seen, created_by, read_at, expires_at
-- ============================================================

-- Fresh installs: create modern table directly
CREATE TABLE IF NOT EXISTS notifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    type VARCHAR(50) NOT NULL COMMENT 'patient_called, patient_waiting, xray, payment_pending, appointment_new, ...',
    title VARCHAR(255) NOT NULL,
    message TEXT,
    data LONGTEXT NULL COMMENT 'JSON payload: patient_id, case_id, visit_id, amount, ...',
    link VARCHAR(255) NULL COMMENT 'Relative URL of the related page',
    is_read TINYINT(1) DEFAULT 0,
    is_seen TINYINT(1) DEFAULT 0 COMMENT 'Seen in dropdown but not clicked',
    created_by INT NULL COMMENT 'User who triggered the notification',
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    read_at TIMESTAMP NULL DEFAULT NULL,
    expires_at TIMESTAMP NULL DEFAULT NULL,
    KEY idx_user_unseen (user_id, is_seen),
    KEY idx_user_unread (user_id, is_read),
    KEY idx_expires (expires_at),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Old installs (schema.sql version had type ENUM + no extra columns): upgrade in place
ALTER TABLE notifications MODIFY COLUMN type VARCHAR(50) NOT NULL;
ALTER TABLE notifications ADD COLUMN data LONGTEXT NULL COMMENT 'JSON payload: patient_id, case_id, visit_id, amount, ...';
ALTER TABLE notifications ADD COLUMN link VARCHAR(255) NULL COMMENT 'Relative URL of the related page';
ALTER TABLE notifications ADD COLUMN is_seen TINYINT(1) DEFAULT 0 COMMENT 'Seen in dropdown but not clicked';
ALTER TABLE notifications ADD COLUMN created_by INT NULL COMMENT 'User who triggered the notification';
ALTER TABLE notifications ADD COLUMN read_at TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE notifications ADD COLUMN expires_at TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE notifications ADD KEY idx_user_unseen (user_id, is_seen);
ALTER TABLE notifications ADD KEY idx_user_unread (user_id, is_read);
ALTER TABLE notifications ADD KEY idx_expires (expires_at);

-- Per-user notification preferences (sound on/off, toasts on/off)
CREATE TABLE IF NOT EXISTS notification_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL UNIQUE,
    sound_enabled TINYINT(1) DEFAULT 1,
    toast_enabled TINYINT(1) DEFAULT 1,
    patient_called_enabled TINYINT(1) DEFAULT 1,
    patient_waiting_enabled TINYINT(1) DEFAULT 1,
    xray_ready_enabled TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_notification_settings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Polling bookmark per user
ALTER TABLE users ADD COLUMN last_notification_check TIMESTAMP NULL DEFAULT NULL;
