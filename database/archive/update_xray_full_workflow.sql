ALTER TABLE xray_requests
MODIFY COLUMN payment_status VARCHAR(50) DEFAULT 'pending_payment',
MODIFY COLUMN request_status VARCHAR(50) DEFAULT 'pending_accountant',
ADD COLUMN return_reason TEXT NULL,
ADD COLUMN cancel_reason TEXT NULL,
ADD COLUMN xray_received_at DATETIME NULL,
ADD COLUMN xray_started_at DATETIME NULL,
ADD COLUMN xray_completed_at DATETIME NULL,
ADD COLUMN returned_to_accountant_at DATETIME NULL,
ADD COLUMN returned_by_xray_user_id INT NULL,
ADD COLUMN refunded_at DATETIME NULL,
ADD COLUMN last_updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

CREATE TABLE IF NOT EXISTS xray_request_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    old_status VARCHAR(50) NULL,
    new_status VARCHAR(50) NULL,
    old_payment_status VARCHAR(50) NULL,
    new_payment_status VARCHAR(50) NULL,
    action_type VARCHAR(50) NOT NULL,
    action_note TEXT NULL,
    changed_by_user_id INT NULL,
    changed_by_role VARCHAR(50) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES xray_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;