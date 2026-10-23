-- Migration: case_name_presets.scope_type
-- Date: 2026-09-14
-- Description: Each case name carries its scope (tooth / arch). Picking the
-- name in the new-case dialog pre-checks the matching scope radio.

ALTER TABLE case_name_presets
    ADD COLUMN scope_type VARCHAR(10) NOT NULL DEFAULT 'tooth' AFTER name;
