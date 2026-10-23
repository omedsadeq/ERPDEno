-- Treatment types may describe a service/job rather than one tooth.
-- Existing arch-only types are whole-arch jobs and therefore start toothless.
ALTER TABLE treatments
    ADD COLUMN requires_tooth_number TINYINT(1) NOT NULL DEFAULT 1
    COMMENT '1 = a tooth must be selected, 0 = service/arch work without a tooth number'
    AFTER scope;

UPDATE treatments
SET requires_tooth_number = 0
WHERE scope = 'arch';
