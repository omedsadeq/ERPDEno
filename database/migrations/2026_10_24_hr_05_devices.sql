/*
 * سەرچاوە مرۆییەکان — fingerprint devices, as reported by the sync service.
 *
 * fingerprint-sync/zk_sync.py (a Python service on the clinic's computer)
 * reads punches from the ZKTeco device into hr_punches and, once a minute,
 * writes its state here. The HR dashboard reads this row to show whether the
 * device is connected, when the last punch arrived, and any PIN on the device
 * that has no employee in Lezan.
 *
 * last_seen_at is the service's own heartbeat: when it is old, the service is
 * not running, whatever is_connected last said.
 */

CREATE TABLE IF NOT EXISTS hr_devices (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    ip                   VARCHAR(45)  NOT NULL,
    port                 INT          NOT NULL DEFAULT 4370,
    name                 VARCHAR(100) NULL,
    serial               VARCHAR(50)  NULL,
    model                VARCHAR(100) NULL,
    is_connected         TINYINT(1)   NOT NULL DEFAULT 0,
    last_seen_at         DATETIME     NULL,
    last_connected_at    DATETIME     NULL,
    last_punch_at        DATETIME     NULL,
    device_users         INT          NULL,
    device_records       INT          NULL,
    pending              INT          NOT NULL DEFAULT 0,
    unknown_pins         VARCHAR(255) NULL,
    time_offset_seconds  INT          NULL,
    last_error           VARCHAR(255) NULL,
    service_version      VARCHAR(20)  NULL,
    updated_at           TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_hr_device_ip (ip, port)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
