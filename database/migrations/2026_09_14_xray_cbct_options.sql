-- CBCT (3D) has three scopes (Full Mouth / 2 Segments / 1 Segment), each with
-- or without a radiology report, and each combination has its own price.
-- Keys match XrayManager::CBCT_OPTIONS; the price mode is 'cbct_' + key.
ALTER TABLE xray_pricing MODIFY COLUMN pricing_mode ENUM(
    'full_mouth','left_side','right_side','upper_arch','lower_arch','per_tooth','single',
    'cbct_full_report','cbct_full_no_report',
    'cbct_2seg_report','cbct_2seg_no_report',
    'cbct_1seg_report','cbct_1seg_no_report'
) NOT NULL;

-- Which CBCT option the doctor chose on the request (NULL for other types).
ALTER TABLE xray_requests ADD COLUMN cbct_option VARCHAR(30) NULL AFTER xray_type;
