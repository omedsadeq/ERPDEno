-- Full schema duplicate of the erp_* tables for the new بەشی فرۆشتن (erp2)
-- section, generated from the live erp_* schema (mysqldump --no-data) with
-- erp_ -> erp2_ renamed throughout. Foreign keys to shared platform tables
-- (users) are untouched -- only the ERP's own tables are duplicated.
--
-- FK checks off for the duration: mysqldump emits tables alphabetically, not
-- in dependency order (e.g. erp2_customer_ledger sorts before the
-- erp2_customers row it references), so a straight top-to-bottom run fails
-- with errno 150 otherwise.
SET FOREIGN_KEY_CHECKS=0;

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `erp2_audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(60) NOT NULL,
  `doc_type` varchar(40) DEFAULT NULL,
  `doc_id` bigint(20) unsigned DEFAULT NULL,
  `doc_number` varchar(40) DEFAULT NULL,
  `old_value` mediumtext DEFAULT NULL,
  `new_value` mediumtext DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_erp2_audit_user` (`user_id`),
  KEY `idx_erp2_audit_doc` (`doc_type`,`doc_id`),
  KEY `idx_erp2_audit_created` (`created_at`),
  KEY `idx_erp2_audit_action` (`action`),
  CONSTRAINT `fk_erp2_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `erp2_brands` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `name_ar` varchar(150) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_erp2_brand_active` (`is_active`),
  KEY `idx_erp2_brand_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `erp2_categories` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `name_ar` varchar(150) DEFAULT NULL,
  `parent_id` int(10) unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_erp2_cat_parent` (`parent_id`),
  KEY `idx_erp2_cat_active` (`is_active`),
  KEY `idx_erp2_cat_name` (`name`),
  CONSTRAINT `fk_erp2_cat_parent` FOREIGN KEY (`parent_id`) REFERENCES `erp2_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `erp2_customer_ledger` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` int(10) unsigned NOT NULL,
  `entry_date` date NOT NULL,
  `doc_type` enum('OPENING','SALE','SALE_RETURN','PAYMENT','ADJUSTMENT') NOT NULL,
  `doc_id` bigint(20) unsigned DEFAULT NULL,
  `doc_number` varchar(40) DEFAULT NULL,
  `debit` decimal(15,2) NOT NULL DEFAULT 0.00,
  `credit` decimal(15,2) NOT NULL DEFAULT 0.00,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp2_cl_cust_date` (`customer_id`,`entry_date`,`id`),
  KEY `idx_erp2_cl_doc` (`doc_type`,`doc_id`),
  KEY `idx_erp2_cl_created` (`created_at`),
  CONSTRAINT `fk_erp2_cl_cust` FOREIGN KEY (`customer_id`) REFERENCES `erp2_customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `erp2_customer_payments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `payment_number` varchar(40) NOT NULL,
  `customer_id` int(10) unsigned NOT NULL,
  `payment_date` date NOT NULL,
  `amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `payment_method` enum('cash','bank','card','other') NOT NULL DEFAULT 'cash',
  `reference` varchar(80) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('confirmed','cancelled') NOT NULL DEFAULT 'confirmed',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp2_cp_number` (`payment_number`),
  KEY `idx_erp2_cp_customer` (`customer_id`),
  KEY `idx_erp2_cp_date` (`payment_date`),
  KEY `idx_erp2_cp_created` (`created_at`),
  CONSTRAINT `fk_erp2_cp_customer` FOREIGN KEY (`customer_id`) REFERENCES `erp2_customers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `erp2_customers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `customer_code` varchar(30) NOT NULL,
  `name` varchar(200) NOT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `opening_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `credit_limit` decimal(15,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `patient_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `is_walk_in` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp2_cust_code` (`customer_code`),
  UNIQUE KEY `uq_erp2_cust_user` (`user_id`),
  KEY `idx_erp2_cust_name` (`name`),
  KEY `idx_erp2_cust_phone` (`phone`),
  KEY `idx_erp2_cust_active` (`is_active`),
  KEY `idx_erp2_cust_patient` (`patient_id`),
  KEY `fk_erp2_cust_creator` (`created_by`),
  CONSTRAINT `fk_erp2_cust_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp2_cust_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp2_cust_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `erp2_invoice_payments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `payment_kind` enum('customer','supplier') NOT NULL,
  `payment_id` bigint(20) unsigned NOT NULL,
  `invoice_id` bigint(20) unsigned NOT NULL,
  `amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_erp2_ip_payment` (`payment_kind`,`payment_id`),
  KEY `idx_erp2_ip_invoice` (`payment_kind`,`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `erp2_number_sequences` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `doc_type` varchar(20) NOT NULL,
  `year` smallint(5) unsigned NOT NULL,
  `warehouse_id` int(10) unsigned NOT NULL DEFAULT 0,
  `prefix` varchar(12) NOT NULL,
  `padding` tinyint(3) unsigned NOT NULL DEFAULT 6,
  `next_number` int(10) unsigned NOT NULL DEFAULT 1,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp2_seq` (`doc_type`,`year`,`warehouse_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `erp2_product_units` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int(10) unsigned NOT NULL,
  `unit_id` int(10) unsigned NOT NULL,
  `factor` decimal(15,4) NOT NULL DEFAULT 1.0000,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `barcode` varchar(64) DEFAULT NULL,
  `sale_price` decimal(15,2) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp2_pu` (`product_id`,`unit_id`),
  UNIQUE KEY `uq_erp2_pu_barcode` (`barcode`),
  KEY `idx_erp2_pu_product` (`product_id`),
  KEY `fk_erp2_pu_unit` (`unit_id`),
  KEY `idx_erp2_pu_default` (`product_id`,`is_default`),
  CONSTRAINT `fk_erp2_pu_product` FOREIGN KEY (`product_id`) REFERENCES `erp2_products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_erp2_pu_unit` FOREIGN KEY (`unit_id`) REFERENCES `erp2_units` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `erp2_products` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `product_code` varchar(50) NOT NULL,
  `barcode` varchar(64) DEFAULT NULL,
  `name` varchar(200) NOT NULL,
  `name_ar` varchar(200) DEFAULT NULL,
  `name_ku` varchar(200) DEFAULT NULL,
  `category_id` int(10) unsigned DEFAULT NULL,
  `brand_id` int(10) unsigned DEFAULT NULL,
  `unit_id` int(10) unsigned DEFAULT NULL,
  `supplier_id` int(10) unsigned DEFAULT NULL,
  `purchase_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `sale_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `min_sale_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `wholesale_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `min_stock` decimal(15,3) NOT NULL DEFAULT 0.000,
  `max_stock` decimal(15,3) NOT NULL DEFAULT 0.000,
  `track_batch` tinyint(1) NOT NULL DEFAULT 0,
  `track_expiry` tinyint(1) NOT NULL DEFAULT 0,
  `expiry_date` date DEFAULT NULL,
  `batch_number` varchar(80) DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp2_prod_code` (`product_code`),
  UNIQUE KEY `uq_erp2_prod_barcode` (`barcode`),
  KEY `idx_erp2_prod_name` (`name`),
  KEY `idx_erp2_prod_cat` (`category_id`),
  KEY `idx_erp2_prod_brand` (`brand_id`),
  KEY `idx_erp2_prod_supplier` (`supplier_id`),
  KEY `idx_erp2_prod_active` (`is_active`),
  KEY `idx_erp2_prod_expiry` (`expiry_date`),
  KEY `idx_erp2_prod_active_name` (`is_active`,`name`),
  KEY `fk_erp2_prod_unit` (`unit_id`),
  KEY `fk_erp2_prod_creator` (`created_by`),
  FULLTEXT KEY `ft_erp2_prod_search` (`name`,`name_ar`,`name_ku`),
  CONSTRAINT `fk_erp2_prod_brand` FOREIGN KEY (`brand_id`) REFERENCES `erp2_brands` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp2_prod_cat` FOREIGN KEY (`category_id`) REFERENCES `erp2_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp2_prod_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp2_prod_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `erp2_suppliers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp2_prod_unit` FOREIGN KEY (`unit_id`) REFERENCES `erp2_units` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `erp2_purchase_invoices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_number` varchar(40) NOT NULL,
  `supplier_invoice_no` varchar(60) DEFAULT NULL,
  `supplier_id` int(10) unsigned NOT NULL,
  `warehouse_id` int(10) unsigned NOT NULL,
  `invoice_date` date NOT NULL,
  `subtotal` decimal(15,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `grand_total` decimal(15,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `payment_type` enum('cash','credit','partial') NOT NULL DEFAULT 'cash',
  `status` enum('draft','confirmed','cancelled') NOT NULL DEFAULT 'confirmed',
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancelled_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp2_pi_number` (`invoice_number`),
  KEY `idx_erp2_pi_supplier` (`supplier_id`),
  KEY `idx_erp2_pi_wh` (`warehouse_id`),
  KEY `idx_erp2_pi_date` (`invoice_date`),
  KEY `idx_erp2_pi_status` (`status`),
  KEY `idx_erp2_pi_created` (`created_at`),
  CONSTRAINT `fk_erp2_pi_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `erp2_suppliers` (`id`),
  CONSTRAINT `fk_erp2_pi_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `erp2_warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `erp2_purchase_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_id` bigint(20) unsigned NOT NULL,
  `product_id` int(10) unsigned NOT NULL,
  `unit_id` int(10) unsigned DEFAULT NULL,
  `unit_factor` decimal(15,4) NOT NULL DEFAULT 1.0000,
  `quantity` decimal(15,3) NOT NULL DEFAULT 0.000,
  `unit_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `line_total` decimal(15,2) NOT NULL DEFAULT 0.00,
  `batch_number` varchar(80) DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `returned_qty` decimal(15,3) NOT NULL DEFAULT 0.000,
  PRIMARY KEY (`id`),
  KEY `idx_erp2_pit_invoice` (`invoice_id`),
  KEY `idx_erp2_pit_product` (`product_id`),
  CONSTRAINT `fk_erp2_pit_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `erp2_purchase_invoices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_erp2_pit_product` FOREIGN KEY (`product_id`) REFERENCES `erp2_products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `erp2_purchase_return_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `return_id` bigint(20) unsigned NOT NULL,
  `product_id` int(10) unsigned NOT NULL,
  `quantity` decimal(15,3) NOT NULL DEFAULT 0.000,
  `unit_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `unit_cost` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `line_total` decimal(15,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_erp2_prit_return` (`return_id`),
  KEY `idx_erp2_prit_product` (`product_id`),
  CONSTRAINT `fk_erp2_prit_product` FOREIGN KEY (`product_id`) REFERENCES `erp2_products` (`id`),
  CONSTRAINT `fk_erp2_prit_return` FOREIGN KEY (`return_id`) REFERENCES `erp2_purchase_returns` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `erp2_purchase_returns` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `return_number` varchar(40) NOT NULL,
  `original_invoice_id` bigint(20) unsigned DEFAULT NULL,
  `supplier_id` int(10) unsigned NOT NULL,
  `warehouse_id` int(10) unsigned NOT NULL,
  `return_date` date NOT NULL,
  `subtotal` decimal(15,2) NOT NULL DEFAULT 0.00,
  `grand_total` decimal(15,2) NOT NULL DEFAULT 0.00,
  `reason` varchar(255) DEFAULT NULL,
  `status` enum('confirmed','cancelled') NOT NULL DEFAULT 'confirmed',
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp2_pr_number` (`return_number`),
  KEY `idx_erp2_pr_supplier` (`supplier_id`),
  KEY `idx_erp2_pr_original` (`original_invoice_id`),
  KEY `idx_erp2_pr_date` (`return_date`),
  KEY `idx_erp2_pr_wh` (`warehouse_id`),
  CONSTRAINT `fk_erp2_pr_original` FOREIGN KEY (`original_invoice_id`) REFERENCES `erp2_purchase_invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp2_pr_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `erp2_suppliers` (`id`),
  CONSTRAINT `fk_erp2_pr_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `erp2_warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `erp2_sales_invoices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_number` varchar(40) NOT NULL,
  `customer_id` int(10) unsigned NOT NULL,
  `warehouse_id` int(10) unsigned NOT NULL,
  `invoice_date` date NOT NULL,
  `subtotal` decimal(15,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `grand_total` decimal(15,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_cost` decimal(15,2) NOT NULL DEFAULT 0.00,
  `payment_type` enum('cash','credit','partial') NOT NULL DEFAULT 'cash',
  `status` enum('draft','confirmed','cancelled') NOT NULL DEFAULT 'confirmed',
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancelled_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp2_si_number` (`invoice_number`),
  KEY `idx_erp2_si_customer` (`customer_id`),
  KEY `idx_erp2_si_wh` (`warehouse_id`),
  KEY `idx_erp2_si_date` (`invoice_date`),
  KEY `idx_erp2_si_status` (`status`),
  KEY `idx_erp2_si_created` (`created_at`),
  KEY `idx_erp2_si_creator` (`created_by`),
  KEY `idx_erp2_si_date_status` (`invoice_date`,`status`),
  CONSTRAINT `fk_erp2_si_customer` FOREIGN KEY (`customer_id`) REFERENCES `erp2_customers` (`id`),
  CONSTRAINT `fk_erp2_si_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `erp2_warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `erp2_sales_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_id` bigint(20) unsigned NOT NULL,
  `product_id` int(10) unsigned NOT NULL,
  `unit_id` int(10) unsigned DEFAULT NULL,
  `unit_factor` decimal(15,4) NOT NULL DEFAULT 1.0000,
  `quantity` decimal(15,3) NOT NULL DEFAULT 0.000,
  `unit_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `unit_cost` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `discount_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `line_total` decimal(15,2) NOT NULL DEFAULT 0.00,
  `line_cost` decimal(15,2) NOT NULL DEFAULT 0.00,
  `line_profit` decimal(15,2) NOT NULL DEFAULT 0.00,
  `returned_qty` decimal(15,3) NOT NULL DEFAULT 0.000,
  PRIMARY KEY (`id`),
  KEY `idx_erp2_sit_invoice` (`invoice_id`),
  KEY `idx_erp2_sit_product` (`product_id`),
  CONSTRAINT `fk_erp2_sit_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `erp2_sales_invoices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_erp2_sit_product` FOREIGN KEY (`product_id`) REFERENCES `erp2_products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `erp2_sales_return_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `return_id` bigint(20) unsigned NOT NULL,
  `product_id` int(10) unsigned NOT NULL,
  `quantity` decimal(15,3) NOT NULL DEFAULT 0.000,
  `unit_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `unit_cost` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `line_total` decimal(15,2) NOT NULL DEFAULT 0.00,
  `line_cost` decimal(15,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_erp2_srit_return` (`return_id`),
  KEY `idx_erp2_srit_product` (`product_id`),
  CONSTRAINT `fk_erp2_srit_product` FOREIGN KEY (`product_id`) REFERENCES `erp2_products` (`id`),
  CONSTRAINT `fk_erp2_srit_return` FOREIGN KEY (`return_id`) REFERENCES `erp2_sales_returns` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `erp2_sales_returns` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `return_number` varchar(40) NOT NULL,
  `original_invoice_id` bigint(20) unsigned DEFAULT NULL,
  `customer_id` int(10) unsigned NOT NULL,
  `warehouse_id` int(10) unsigned NOT NULL,
  `return_date` date NOT NULL,
  `subtotal` decimal(15,2) NOT NULL DEFAULT 0.00,
  `grand_total` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_cost` decimal(15,2) NOT NULL DEFAULT 0.00,
  `reason` varchar(255) DEFAULT NULL,
  `status` enum('confirmed','cancelled') NOT NULL DEFAULT 'confirmed',
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp2_sr_number` (`return_number`),
  KEY `idx_erp2_sr_customer` (`customer_id`),
  KEY `idx_erp2_sr_original` (`original_invoice_id`),
  KEY `idx_erp2_sr_date` (`return_date`),
  KEY `idx_erp2_sr_wh` (`warehouse_id`),
  CONSTRAINT `fk_erp2_sr_customer` FOREIGN KEY (`customer_id`) REFERENCES `erp2_customers` (`id`),
  CONSTRAINT `fk_erp2_sr_original` FOREIGN KEY (`original_invoice_id`) REFERENCES `erp2_sales_invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp2_sr_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `erp2_warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `erp2_stock_adjustment_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `adjustment_id` bigint(20) unsigned NOT NULL,
  `product_id` int(10) unsigned NOT NULL,
  `qty_before` decimal(15,3) NOT NULL DEFAULT 0.000,
  `qty_after` decimal(15,3) NOT NULL DEFAULT 0.000,
  `difference` decimal(15,3) NOT NULL DEFAULT 0.000,
  `unit_cost` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `notes` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp2_adjit_adj` (`adjustment_id`),
  KEY `idx_erp2_adjit_product` (`product_id`),
  CONSTRAINT `fk_erp2_adjit_adj` FOREIGN KEY (`adjustment_id`) REFERENCES `erp2_stock_adjustments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_erp2_adjit_product` FOREIGN KEY (`product_id`) REFERENCES `erp2_products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `erp2_stock_adjustments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `adjustment_number` varchar(40) NOT NULL,
  `warehouse_id` int(10) unsigned NOT NULL,
  `adjustment_date` date NOT NULL,
  `reason` enum('OPENING','COUNT','DAMAGE','EXPIRED','LOST','FOUND','CORRECTION') NOT NULL,
  `status` enum('confirmed','cancelled') NOT NULL DEFAULT 'confirmed',
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp2_adj_number` (`adjustment_number`),
  KEY `idx_erp2_adj_wh` (`warehouse_id`),
  KEY `idx_erp2_adj_date` (`adjustment_date`),
  KEY `idx_erp2_adj_reason` (`reason`),
  CONSTRAINT `fk_erp2_adj_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `erp2_warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `erp2_stock_movements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int(10) unsigned NOT NULL,
  `warehouse_id` int(10) unsigned NOT NULL,
  `transaction_type` enum('PURCHASE','PURCHASE_RETURN','SALE','SALE_RETURN','TRANSFER_IN','TRANSFER_OUT','ADJUSTMENT','DAMAGE','OPENING') NOT NULL,
  `transaction_id` bigint(20) unsigned DEFAULT NULL,
  `transaction_item_id` bigint(20) unsigned DEFAULT NULL,
  `document_number` varchar(40) DEFAULT NULL,
  `quantity_in` decimal(15,3) NOT NULL DEFAULT 0.000,
  `quantity_out` decimal(15,3) NOT NULL DEFAULT 0.000,
  `unit_cost` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `balance_after` decimal(15,3) NOT NULL DEFAULT 0.000,
  `batch_number` varchar(80) DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp2_mov_prod_wh` (`product_id`,`warehouse_id`,`id`),
  KEY `idx_erp2_mov_wh` (`warehouse_id`),
  KEY `idx_erp2_mov_type` (`transaction_type`),
  KEY `idx_erp2_mov_doc` (`transaction_type`,`transaction_id`),
  KEY `idx_erp2_mov_created` (`created_at`),
  KEY `idx_erp2_mov_creator` (`created_by`),
  CONSTRAINT `fk_erp2_mov_product` FOREIGN KEY (`product_id`) REFERENCES `erp2_products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_erp2_mov_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `erp2_warehouses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `erp2_stock_transfer_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `transfer_id` bigint(20) unsigned NOT NULL,
  `product_id` int(10) unsigned NOT NULL,
  `quantity` decimal(15,3) NOT NULL DEFAULT 0.000,
  `unit_cost` decimal(15,4) NOT NULL DEFAULT 0.0000,
  PRIMARY KEY (`id`),
  KEY `idx_erp2_trit_transfer` (`transfer_id`),
  KEY `idx_erp2_trit_product` (`product_id`),
  CONSTRAINT `fk_erp2_trit_product` FOREIGN KEY (`product_id`) REFERENCES `erp2_products` (`id`),
  CONSTRAINT `fk_erp2_trit_transfer` FOREIGN KEY (`transfer_id`) REFERENCES `erp2_stock_transfers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `erp2_stock_transfers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `transfer_number` varchar(40) NOT NULL,
  `from_warehouse_id` int(10) unsigned NOT NULL,
  `to_warehouse_id` int(10) unsigned NOT NULL,
  `transfer_date` date NOT NULL,
  `status` enum('draft','confirmed','cancelled') NOT NULL DEFAULT 'confirmed',
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp2_tr_number` (`transfer_number`),
  KEY `idx_erp2_tr_from` (`from_warehouse_id`),
  KEY `idx_erp2_tr_to` (`to_warehouse_id`),
  KEY `idx_erp2_tr_date` (`transfer_date`),
  CONSTRAINT `fk_erp2_tr_from` FOREIGN KEY (`from_warehouse_id`) REFERENCES `erp2_warehouses` (`id`),
  CONSTRAINT `fk_erp2_tr_to` FOREIGN KEY (`to_warehouse_id`) REFERENCES `erp2_warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `erp2_supplier_ledger` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `supplier_id` int(10) unsigned NOT NULL,
  `entry_date` date NOT NULL,
  `doc_type` enum('OPENING','PURCHASE','PURCHASE_RETURN','PAYMENT','ADJUSTMENT') NOT NULL,
  `doc_id` bigint(20) unsigned DEFAULT NULL,
  `doc_number` varchar(40) DEFAULT NULL,
  `debit` decimal(15,2) NOT NULL DEFAULT 0.00,
  `credit` decimal(15,2) NOT NULL DEFAULT 0.00,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp2_sl_sup_date` (`supplier_id`,`entry_date`,`id`),
  KEY `idx_erp2_sl_doc` (`doc_type`,`doc_id`),
  KEY `idx_erp2_sl_created` (`created_at`),
  CONSTRAINT `fk_erp2_sl_sup` FOREIGN KEY (`supplier_id`) REFERENCES `erp2_suppliers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `erp2_supplier_payments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `payment_number` varchar(40) NOT NULL,
  `supplier_id` int(10) unsigned NOT NULL,
  `payment_date` date NOT NULL,
  `amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `payment_method` enum('cash','bank','card','other') NOT NULL DEFAULT 'cash',
  `reference` varchar(80) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('confirmed','cancelled') NOT NULL DEFAULT 'confirmed',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp2_sp_number` (`payment_number`),
  KEY `idx_erp2_sp_supplier` (`supplier_id`),
  KEY `idx_erp2_sp_date` (`payment_date`),
  KEY `idx_erp2_sp_created` (`created_at`),
  CONSTRAINT `fk_erp2_sp_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `erp2_suppliers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `erp2_suppliers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `supplier_code` varchar(30) NOT NULL,
  `name` varchar(200) NOT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `contact_person` varchar(150) DEFAULT NULL,
  `opening_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `credit_limit` decimal(15,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp2_sup_code` (`supplier_code`),
  KEY `idx_erp2_sup_name` (`name`),
  KEY `idx_erp2_sup_phone` (`phone`),
  KEY `idx_erp2_sup_active` (`is_active`),
  KEY `fk_erp2_sup_creator` (`created_by`),
  CONSTRAINT `fk_erp2_sup_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `erp2_units` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL,
  `name` varchar(80) NOT NULL,
  `name_ar` varchar(80) DEFAULT NULL,
  `decimals` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp2_unit_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `erp2_user_warehouses` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `warehouse_id` int(10) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp2_uw` (`user_id`,`warehouse_id`),
  KEY `idx_erp2_uw_user` (`user_id`),
  KEY `fk_erp2_uw_wh` (`warehouse_id`),
  CONSTRAINT `fk_erp2_uw_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_erp2_uw_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `erp2_warehouses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `erp2_warehouse_product_stock` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `warehouse_id` int(10) unsigned NOT NULL,
  `product_id` int(10) unsigned NOT NULL,
  `quantity` decimal(15,3) NOT NULL DEFAULT 0.000,
  `average_cost` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp2_wps` (`warehouse_id`,`product_id`),
  KEY `idx_erp2_wps_product` (`product_id`),
  KEY `idx_erp2_wps_qty` (`quantity`),
  CONSTRAINT `fk_erp2_wps_product` FOREIGN KEY (`product_id`) REFERENCES `erp2_products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_erp2_wps_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `erp2_warehouses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `erp2_warehouses` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(30) NOT NULL,
  `name` varchar(150) NOT NULL,
  `location` varchar(255) DEFAULT NULL,
  `manager_id` int(11) DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp2_wh_code` (`code`),
  KEY `idx_erp2_wh_active` (`is_active`),
  KEY `fk_erp2_wh_manager` (`manager_id`),
  CONSTRAINT `fk_erp2_wh_manager` FOREIGN KEY (`manager_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

SET FOREIGN_KEY_CHECKS=1;
