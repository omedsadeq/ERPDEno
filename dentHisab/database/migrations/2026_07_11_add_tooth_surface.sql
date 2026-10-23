-- Smart dental chart: per-surface treatment recording
-- Stores a comma-separated list of tooth surfaces the treatment was applied to
-- (mesial, distal, occlusal, incisal, buccal, lingual, root). NULL = whole tooth.

ALTER TABLE case_treatments
    ADD COLUMN tooth_surface VARCHAR(50) NULL AFTER tooth_number;
