-- A later debt collection starts as a doctor-owned draft receipt. Draft rows
-- are local to the case and are excluded from accounting queues and balances
-- until the doctor explicitly sends them (status -> pending).
ALTER TABLE payments
    MODIFY COLUMN status ENUM('draft','pending','approved','rejected','refunded') NOT NULL DEFAULT 'pending';

