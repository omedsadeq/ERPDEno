-- داواکاری مەواد is the doctor's own request to the store, made on
-- doctors/material-requests.php — it has nothing to do with a case. Requests
-- made from a case before this keep their case; new ones carry none.
ALTER TABLE erp_material_requests MODIFY case_id INT NULL;
