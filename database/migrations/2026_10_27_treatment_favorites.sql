-- ★ on a treatment card (a case's treatment picker): each user's own
-- favourite treatment types, shown first in their picker.

CREATE TABLE IF NOT EXISTS user_treatment_favorites (
    user_id      INT NOT NULL,
    treatment_id INT NOT NULL,
    created_at   TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, treatment_id),
    KEY idx_utf_treatment (treatment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
