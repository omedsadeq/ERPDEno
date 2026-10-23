-- Database Backup and Restore Module
-- Creates backup_history and seeds backup settings into the existing settings table.

CREATE TABLE IF NOT EXISTS backup_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    file_name VARCHAR(255) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    backup_type ENUM('full','structure','data','tables') NOT NULL DEFAULT 'full',
    compression ENUM('none','gzip','zip') NOT NULL DEFAULT 'gzip',
    method VARCHAR(20) NULL,
    tables_included TEXT NULL,
    checksum_sha256 CHAR(64) NULL,
    status ENUM('running','completed','failed','deleted') NOT NULL DEFAULT 'running',
    error_message TEXT NULL,
    trigger_source ENUM('manual','scheduled','pre_restore','upload') NOT NULL DEFAULT 'manual',
    db_name VARCHAR(100) NULL,
    created_by INT NULL,
    started_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    KEY idx_backup_status (status),
    KEY idx_backup_started (started_at),
    CONSTRAINT fk_backup_history_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Scheduled backup + retention settings (INSERT IGNORE keeps existing values)
INSERT IGNORE INTO settings (setting_key, setting_value, setting_type, description) VALUES
('backup_auto_enabled',     '0',     'boolean', 'Enable scheduled automatic database backups'),
('backup_auto_frequency',   'daily', 'text',    'Automatic backup frequency: daily, weekly or monthly'),
('backup_auto_time',        '02:00', 'text',    'Time of day (HH:MM) the automatic backup runs'),
('backup_auto_weekday',     '5',     'number',  'Weekday for weekly backups (0=Sunday .. 6=Saturday)'),
('backup_auto_monthday',    '1',     'number',  'Day of month for monthly backups (1-28)'),
('backup_auto_type',        'full',  'text',    'Type of automatic backup: full, structure or data'),
('backup_auto_compression', 'gzip',  'text',    'Compression of automatic backups: gzip, zip or none'),
('backup_retention_count',  '10',    'number',  'Keep at most this many completed backups (0 = unlimited)'),
('backup_retention_days',   '30',    'number',  'Delete backups older than this many days (0 = never)'),
('backup_notify',           '1',     'boolean', 'Notify admins about scheduled backup success or failure'),
('backup_last_auto_run',    '',      'text',    'Timestamp of the last automatic backup run');

INSERT IGNORE INTO settings (setting_key, setting_value, setting_type, description)
SELECT 'backup_cron_token', SHA2(CONCAT(RAND(), NOW(), UUID()), 256), 'text', 'Secret token for the web cron endpoint of the backup module';
