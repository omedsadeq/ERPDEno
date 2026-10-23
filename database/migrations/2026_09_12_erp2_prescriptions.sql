-- دەرمان — the bridge between the dental case and بەشی فرۆشتن (erp2).
--
-- The doctor writes a prescription against a CASE; the sales counter fulfils
-- it as a normal erp2 sale. Deliberately its own pair of tables rather than a
-- draft erp2_sales_invoice: a prescription is a clinical instruction (it
-- carries a dose per line, and may never be dispensed at all), while a sales
-- invoice is money and stock. Keeping them apart means an un-dispensed
-- prescription never touches stock or the ledger.
--
-- product_name is snapshotted on the line so an old prescription still reads
-- correctly after a product is renamed or deactivated.
CREATE TABLE IF NOT EXISTS erp2_prescriptions (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    patient_id   INT NOT NULL,
    case_id      INT DEFAULT NULL,
    doctor_id    INT DEFAULT NULL,
    customer_id  INT UNSIGNED DEFAULT NULL,
    status       ENUM('pending','sold','cancelled') NOT NULL DEFAULT 'pending',
    notes        TEXT DEFAULT NULL,
    invoice_id   INT UNSIGNED DEFAULT NULL,
    sold_at      DATETIME DEFAULT NULL,
    cancelled_at DATETIME DEFAULT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by   INT DEFAULT NULL,
    KEY idx_erp2_presc_status (status, created_at),
    KEY idx_erp2_presc_patient (patient_id),
    KEY idx_erp2_presc_case (case_id),
    CONSTRAINT fk_erp2_presc_patient  FOREIGN KEY (patient_id)  REFERENCES patients(id) ON DELETE CASCADE,
    CONSTRAINT fk_erp2_presc_case     FOREIGN KEY (case_id)     REFERENCES cases(id) ON DELETE SET NULL,
    CONSTRAINT fk_erp2_presc_doctor   FOREIGN KEY (doctor_id)   REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_erp2_presc_customer FOREIGN KEY (customer_id) REFERENCES erp2_customers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS erp2_prescription_items (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    prescription_id INT UNSIGNED NOT NULL,
    product_id      INT UNSIGNED NOT NULL,
    product_name    VARCHAR(200) NOT NULL,
    quantity        DECIMAL(15,3) NOT NULL DEFAULT 1,
    dosage          VARCHAR(255) DEFAULT NULL,
    notes           VARCHAR(255) DEFAULT NULL,
    KEY idx_erp2_presc_item_presc (prescription_id),
    KEY idx_erp2_presc_item_product (product_id),
    CONSTRAINT fk_erp2_presc_item_presc   FOREIGN KEY (prescription_id) REFERENCES erp2_prescriptions(id) ON DELETE CASCADE,
    CONSTRAINT fk_erp2_presc_item_product FOREIGN KEY (product_id)      REFERENCES erp2_products(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
