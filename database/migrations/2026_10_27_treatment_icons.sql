-- Treatment types get an illustrated icon (assets/js/treatment-icons.js),
-- chosen when the type is made and shown on its card in a case's treatment
-- picker. NULL for the types made before: the picker guesses one from the
-- name and category until someone picks one.

ALTER TABLE treatments ADD COLUMN IF NOT EXISTS icon VARCHAR(30) NULL DEFAULT NULL AFTER category;
