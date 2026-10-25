-- دەرمانخانە (erp2): the cash handed back on a sales return.
--
-- A return credits the customer for the goods (SALE_RETURN credit). When the
-- money is given back over the counter — always for the walk-in customer,
-- by choice for a named one (erp2/pos.php «گەڕاندنەوە») — that cash is stored
-- here and posted as a second SALE_RETURN row on the debit side, the mirror
-- of a cash sale's PAYMENT row, so the customer's balance stays right and the
-- cashier's day shows the money that left the drawer.

ALTER TABLE erp2_sales_returns ADD COLUMN IF NOT EXISTS refund_amount DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER grand_total;
