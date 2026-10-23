-- پلانی چارەسەر — the first-visit examination estimate.
--
-- The doctor examines the patient, marks on the chart what each tooth needs and
-- what it would cost. On paper this was a quote handed to the patient with a
-- copy kept by the clinic; nothing was owed and nothing was earned by writing
-- it. These tables hold exactly that, and they are deliberately OUTSIDE the
-- money model:
--
--   * No accounting code reads them. AccountingLedger, PatientLedger,
--     PatientFinance, financial-summary and every case/invoice total are built
--     from case_invoices / case_treatments / payments only, so an estimate
--     cannot reach a financial figure however large it is.
--   * A plan is NOT a case and has no invoice. The money only becomes real
--     when an item is transferred (گواستنەوە) into a case, which inserts a
--     normal case_treatments row through the same CaseManager::addTreatment()
--     path a doctor uses by hand — from that point on it is ordinary billable
--     work and the estimate columns here are frozen history.
--
-- That is the whole point of the feature, so keep it that way: never join
-- these tables into a financial query.
CREATE TABLE IF NOT EXISTS treatment_plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    plan_number VARCHAR(30) NOT NULL,
    patient_id INT NOT NULL,
    doctor_id INT NOT NULL,
    -- Which tooth set the chart was drawn on, same meaning as cases.dentition.
    dentition ENUM('permanent','primary') NOT NULL DEFAULT 'permanent',
    chief_complaint TEXT NULL,
    notes TEXT NULL,
    -- How long the whole course will take, in the doctor's own words
    -- ("٢ هەفتە", "٣ سەردان"). Free text on purpose: a treatment course is
    -- counted in visits and weeks, not in minutes. Per-item chair time is the
    -- numeric estimate, on treatment_plan_items.estimated_minutes.
    duration_text VARCHAR(100) NULL,
    -- 'open' until something is transferred; 'partial' while some items have
    -- gone to a case and others have not; 'transferred' once none are left.
    status ENUM('open','partial','transferred','cancelled') NOT NULL DEFAULT 'open',
    -- Caches recomputed by TreatmentPlan::recomputeTotals() after every write.
    -- Estimates only — see the table comment.
    total_estimated DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_discount DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_final DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_minutes INT NOT NULL DEFAULT 0,
    cancel_reason TEXT NULL,
    cancelled_at DATETIME NULL,
    cancelled_by INT NULL,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_treatment_plan_number (plan_number),
    KEY idx_treatment_plan_patient (patient_id, status),
    KEY idx_treatment_plan_doctor (doctor_id, created_at),
    CONSTRAINT treatment_plans_patient_fk FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    CONSTRAINT treatment_plans_doctor_fk FOREIGN KEY (doctor_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One planned job: what this tooth needs and what it would cost.
-- Column shapes mirror case_treatments so a transfer is a straight copy.
CREATE TABLE IF NOT EXISTS treatment_plan_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    plan_id INT NOT NULL,
    treatment_id INT NULL,
    -- Snapshot of the catalogue name, like case_treatments.treatment_name: a
    -- quote must still read correctly after the catalogue is renamed.
    treatment_name VARCHAR(100) NOT NULL,
    tooth_number INT NULL,
    tooth_surface VARCHAR(50) NULL,
    arch_type ENUM('none','upper','lower') NOT NULL DEFAULT 'none',
    quantity INT NOT NULL DEFAULT 1,
    unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    discount DECIMAL(12,2) NOT NULL DEFAULT 0,
    final_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    -- Estimated chair time for this one job. Feeds the plan's total and is
    -- what makes booking the follow-up appointment possible.
    estimated_minutes INT NULL,
    notes TEXT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    status ENUM('planned','transferred','cancelled') NOT NULL DEFAULT 'planned',
    -- Where this item ended up once the patient agreed. Keeping both ids means
    -- the plan can show "→ کەیس X" and, just as importantly, that an item can
    -- never be transferred twice into two different cases.
    transferred_case_id INT NULL,
    transferred_treatment_id INT NULL,
    transferred_at DATETIME NULL,
    transferred_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_tpi_plan (plan_id, sort_order),
    KEY idx_tpi_status (status),
    KEY idx_tpi_case (transferred_case_id),
    CONSTRAINT tpi_plan_fk FOREIGN KEY (plan_id) REFERENCES treatment_plans(id) ON DELETE CASCADE,
    CONSTRAINT tpi_treatment_fk FOREIGN KEY (treatment_id) REFERENCES treatments(id) ON DELETE SET NULL,
    CONSTRAINT tpi_case_fk FOREIGN KEY (transferred_case_id) REFERENCES cases(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
