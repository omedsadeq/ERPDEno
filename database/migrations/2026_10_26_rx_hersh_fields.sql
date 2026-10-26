-- ڕەچەتە: Dr. Hêrsh's sheet is built by the system from his details (the
-- clinic template, admin/rx-templates.php), like every other doctor's — so
-- everything on it can be edited. His panel picture from «lezan a5 v3.pdf»
-- stays stored and can be chosen again on admin/doctor-rx.php («وێنەی ئامادە»).
UPDATE doctor_rx_profiles SET panel_mode = 'fields'
WHERE user_id = 8 AND panel_mode = 'image' AND display_name = 'هێرش حسن شێروانی';
