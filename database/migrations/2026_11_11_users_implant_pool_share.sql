-- How much of a doctor's implant cost comes off «کۆی ماوە دوای خەرجی» before
-- his percentage is taken (Book1.xlsx): all of it for د.هێرش, half for some
-- doctors. NULL = 100. The rest of the cost stays with لێزان. Set beside the
-- store percentages on admin/expenses-inventory.php («ڕێژەی دکتۆرەکان»).
ALTER TABLE users ADD COLUMN implant_pool_share DECIMAL(5,2) NULL AFTER store_share_implant;
