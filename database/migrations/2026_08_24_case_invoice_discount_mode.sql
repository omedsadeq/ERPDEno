-- Remember HOW a receipt's discount was given, not just the resulting money.
--
-- "10% off" and "455,500 off" can be the same amount today, but the staff need
-- to see which one was agreed — and a percentage must keep showing as a
-- percentage on screen and on the printed receipt. Storing only the computed
-- amount threw that away.
--
--   discount_mode  'amount'  → discount_value is the money itself
--                  'percent' → discount_value is the percentage of total_amount
--   discount_amount stays the computed money and remains the single figure all
--   the balance math (FIFO, net, remaining) reads.

ALTER TABLE case_invoices
    ADD COLUMN discount_mode ENUM('amount','percent') NOT NULL DEFAULT 'amount' AFTER discount_amount;

ALTER TABLE case_invoices
    ADD COLUMN discount_value DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER discount_mode;

-- Existing discounts were all entered as flat amounts.
UPDATE case_invoices SET discount_value = discount_amount WHERE discount_amount > 0 AND discount_value = 0;
