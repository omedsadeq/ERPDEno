-- Which visit receipt an installment plan belongs to.
--
-- The clinic works receipt by receipt: a patient agrees instalments for ONE
-- day's work, not for the whole case. The plan is still paid out of the case's
-- money — the FIFO waterfall is unchanged — this only records which receipt the
-- schedule was agreed against, so the plan can show its وەسڵ and default to
-- that receipt's remaining rather than the case total.
--
-- There is still only ONE active plan per case at a time: a receipt may only be
-- put on a plan once every earlier receipt is settled, so the planned receipt is
-- always the one the waterfall is currently filling. That is what lets
-- getPlanByCase() and autoAllocatePayment() keep working untouched.
--
-- NULL = a plan made before this, covering the case as a whole.

ALTER TABLE installment_plans
    ADD COLUMN invoice_id INT UNSIGNED NULL DEFAULT NULL AFTER case_id;

ALTER TABLE installment_plans
    ADD KEY idx_installment_plans_invoice (invoice_id);
