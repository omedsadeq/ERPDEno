-- دەرمانخانە (erp2): the walk-in customer the cashier screen (erp2/pos.php)
-- sells to by default. The erp2 tables were duplicated without the seed row the
-- original erp module had, so every sale needed a named customer.
--
-- Only inserted when no walk-in exists yet, so a clinic that already marked
-- one keeps it.
INSERT INTO erp2_customers (customer_code, name, is_walk_in, is_active)
SELECT 'C-WALKIN', 'کڕیاری نەقد', 1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM erp2_customers WHERE is_walk_in = 1)
  AND NOT EXISTS (SELECT 1 FROM erp2_customers WHERE customer_code = 'C-WALKIN');
