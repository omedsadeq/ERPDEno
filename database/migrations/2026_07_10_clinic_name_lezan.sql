-- Migration: Clinic branding — Lezan Dental Clinics (2026-07-10)
-- Sets the clinic name shown in headers, PDFs and receipts to
-- سەنتەری لێزان / Lezan Dental Clinics (settings table, editable
-- later from admin/settings.php).

UPDATE settings SET setting_value = 'سەنتەری لێزان'
WHERE setting_key = 'clinic_name';

UPDATE settings SET setting_value = 'Lezan Dental Clinics'
WHERE setting_key = 'clinic_name_en';

UPDATE settings SET setting_value = 'Lezan Dental Clinics'
WHERE setting_key = 'clinic_subtitle';
