-- ======================================
-- Add selected_teeth and determine_teeth columns to xray_requests table
-- For storing teeth selection from dental chart
-- ======================================

-- Add selected_teeth column for periapical X-ray teeth selection (FDI notation)
ALTER TABLE xray_requests
ADD COLUMN selected_teeth VARCHAR(100) DEFAULT NULL AFTER determine_details;

-- Add determine_teeth column for "Determine" section teeth selection (FDI notation)
ALTER TABLE xray_requests
ADD COLUMN determine_teeth VARCHAR(100) DEFAULT NULL AFTER selected_teeth;

-- Both columns store FDI notation tooth numbers, e.g., "11,12,21,22,36,46"
