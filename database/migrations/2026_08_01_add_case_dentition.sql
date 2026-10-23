-- Adult / child dentition switch for the case tooth chart
-- 'permanent' = 32 teeth, FDI 11-48, labelled 1-8 per quadrant
-- 'primary'   = 20 teeth, FDI 51-85, labelled A-E per quadrant
-- Existing cases stay on the permanent chart, which is what they were charted with.

ALTER TABLE cases
    ADD COLUMN dentition ENUM('permanent','primary')
    NOT NULL DEFAULT 'permanent'
    COMMENT 'Which tooth set the case chart renders'
    AFTER selected_arch;
