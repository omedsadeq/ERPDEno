/*
 * سەرچاوە مرۆییەکان — people left out of the monthly sheet.
 *
 * Set from the monthly sheet itself (hide a column, or «ستوونەکان»). A hidden
 * person is left out of the sheet, its totals, its salary summary, its PDF
 * and its Excel — for everyone, since it is stored here. Their punches and
 * every other report are untouched. The column order is display_order, as
 * before (hr/sort-order.php and the sheet's drag-and-drop both write it).
 */

ALTER TABLE hr_employees ADD COLUMN monthly_hidden TINYINT(1) NOT NULL DEFAULT 0 AFTER display_order;
