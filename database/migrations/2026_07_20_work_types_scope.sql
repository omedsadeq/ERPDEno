-- Migration: per-page scope for work types
-- Date: 2026-07-20
-- Description: A work type added with the "+" button on a page belongs to THAT page only.
--              Seeded/global types keep scope='all' and stay visible everywhere.

ALTER TABLE work_types
    ADD COLUMN scope VARCHAR(30) NOT NULL DEFAULT 'all'
    COMMENT 'all | new_visit | edit_visit | visits | appointments | patients | waiting_room';

ALTER TABLE work_types ADD KEY idx_work_types_scope (scope);
