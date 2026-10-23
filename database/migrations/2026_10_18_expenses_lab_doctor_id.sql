-- expenses_lab.doctor_id (users.id): the doctor a lab bill is charged to. The
-- live database got it by hand; the doctor reports (daily summary, income
-- sheet, income distribution) need it, so every install gets it here.
-- Already present → error 1060, which MigrationRunner ignores.
ALTER TABLE expenses_lab ADD COLUMN doctor_id INT NULL;
