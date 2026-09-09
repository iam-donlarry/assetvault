-- =====================================================================
-- AssetVault — Staff Asset Management System
-- Canonical Database Schema & Initial Master Data
-- Database Engine: MySQL 8.0+ / MariaDB 10.4+
-- Dual Currency Support: Primary (e.g. NGN) & Secondary (e.g. USD)
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
SET time_zone = "+00:00";

-- ---------------------------------------------------------------------
-- 1. SYSTEM SETTINGS
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `system_settings`;
CREATE TABLE `system_settings` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(50) NOT NULL UNIQUE,
    `setting_value` TEXT NOT NULL,
    `setting_group` VARCHAR(30) NOT NULL DEFAULT 'general',
    `description` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. DEPARTMENTS
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `departments`;
CREATE TABLE `departments` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(30) NOT NULL UNIQUE,
    `name` VARCHAR(100) NOT NULL,
    `description` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. LOCATIONS
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `locations`;
CREATE TABLE `locations` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(30) NOT NULL UNIQUE,
    `name` VARCHAR(100) NOT NULL,
    `building` VARCHAR(100) NOT NULL DEFAULT 'Main Office',
    `floor` VARCHAR(50) NOT NULL DEFAULT 'Ground Floor',
    `description` VARCHAR(255) NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. ASSET CATEGORIES
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `asset_categories`;
CREATE TABLE `asset_categories` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(20) NOT NULL UNIQUE,
    `name` VARCHAR(60) NOT NULL UNIQUE,
    `is_serialized` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = requires unique serial number, 0 = bulk/consumable',
    `default_maintenance_interval_days` INT UNSIGNED NULL COMMENT 'Days between scheduled servicing',
    `description` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. SUPPLIERS / VENDORS
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `suppliers`;
CREATE TABLE `suppliers` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(150) NOT NULL,
    `contact_person` VARCHAR(100) NULL,
    `email` VARCHAR(120) NULL,
    `phone` VARCHAR(40) NULL,
    `address` TEXT NULL,
    `notes` TEXT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_supplier_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. EMPLOYEES / STAFF
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `employees`;
CREATE TABLE `employees` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `staff_id` VARCHAR(30) NOT NULL UNIQUE,
    `full_name` VARCHAR(120) NOT NULL,
    `email` VARCHAR(120) NOT NULL UNIQUE,
    `phone` VARCHAR(40) NULL,
    `department_id` INT UNSIGNED NOT NULL,
    `job_title` VARCHAR(100) NOT NULL,
    `line_manager_id` INT UNSIGNED NULL,
    `primary_location_id` INT UNSIGNED NULL,
    `employment_status` ENUM('active', 'onboarding', 'offboarding', 'exited', 'suspended') NOT NULL DEFAULT 'active',
    `m365_assigned` TINYINT(1) NOT NULL DEFAULT 0,
    `m365_license_type` VARCHAR(60) NULL DEFAULT 'Microsoft 365 Business Standard',
    `m365_assigned_date` DATE NULL,
    `start_date` DATE NULL,
    `exit_date` DATE NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_emp_staff_id` (`staff_id`),
    INDEX `idx_emp_email` (`email`),
    INDEX `idx_emp_status` (`employment_status`),
    INDEX `idx_emp_department` (`department_id`),
    INDEX `idx_emp_manager` (`line_manager_id`),
    INDEX `idx_emp_location` (`primary_location_id`),
    CONSTRAINT `fk_emp_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_emp_manager` FOREIGN KEY (`line_manager_id`) REFERENCES `employees` (`id`) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT `fk_emp_location` FOREIGN KEY (`primary_location_id`) REFERENCES `locations` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. ROLES & PERMISSIONS (RBAC)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `roles`;
CREATE TABLE `roles` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(60) NOT NULL UNIQUE,
    `slug` VARCHAR(60) NOT NULL UNIQUE,
    `description` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `permissions`;
CREATE TABLE `permissions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `module` VARCHAR(50) NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `slug` VARCHAR(60) NOT NULL UNIQUE,
    `description` VARCHAR(255) NULL,
    INDEX `idx_perm_module` (`module`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `role_permissions`;
CREATE TABLE `role_permissions` (
    `role_id` INT UNSIGNED NOT NULL,
    `permission_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`role_id`, `permission_id`),
    CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rp_perm` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8. USERS (APPLICATION ACCOUNTS)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(60) NOT NULL UNIQUE,
    `email` VARCHAR(120) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `full_name` VARCHAR(120) NOT NULL,
    `role_id` INT UNSIGNED NOT NULL,
    `employee_id` INT UNSIGNED NULL UNIQUE,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `last_login` DATETIME NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_user_username` (`username`),
    INDEX `idx_user_email` (`email`),
    INDEX `idx_user_role` (`role_id`),
    CONSTRAINT `fk_user_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_user_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8b. PRODUCT CATALOG (PRODUCT MODELS)
-- Defines WHAT items are. Canonical definition of products, brands, models,
-- specifications, tracking type, and weighted average unit cost.
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `product_models`;
CREATE TABLE `product_models` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `product_code` VARCHAR(50) NOT NULL,
    `category_id` INT UNSIGNED NOT NULL,
    `brand` VARCHAR(80) NULL,
    `model_name` VARCHAR(150) NOT NULL,
    `model_number` VARCHAR(100) NULL,
    `tracking_type` ENUM('serialized', 'bulk_quantity') NOT NULL DEFAULT 'serialized',
    `unit_of_measure` VARCHAR(20) NOT NULL DEFAULT 'units',
    `default_purchase_cost` DECIMAL(12,2) NULL,
    `average_unit_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `default_currency` VARCHAR(3) NOT NULL DEFAULT 'NGN',
    `default_warranty_months` INT UNSIGNED NULL DEFAULT 12,
    `min_stock_alert` INT UNSIGNED NOT NULL DEFAULT 5,
    `min_reorder_level` INT UNSIGNED NOT NULL DEFAULT 5,
    `description` TEXT NULL,
    `specifications` TEXT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY `uk_prod_code` (`product_code`),
    UNIQUE KEY `uk_canonical_product` (`category_id`, `brand`, `model_name`, `model_number`),
    INDEX `idx_pm_category` (`category_id`),
    INDEX `idx_pm_tracking` (`tracking_type`),
    INDEX `idx_pm_lookup` (`brand`, `model_name`),
    
    CONSTRAINT `fk_pm_category` FOREIGN KEY (`category_id`) REFERENCES `asset_categories` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 9. ASSETS (INDIVIDUAL SERIALIZED PHYSICAL UNITS ONLY)
-- Strict Invariant: Only items with tracking_type = 'serialized' are registered here.
-- Bulk quantity items exist exclusively in inventory_stock.
-- Supports dual currency (Primary: e.g. NGN, Secondary: e.g. USD)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `assets`;
CREATE TABLE `assets` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT UNSIGNED NULL COMMENT 'Reference to master product catalog model',
    `asset_tag` VARCHAR(50) NOT NULL UNIQUE,
    `legacy_asset_id` VARCHAR(50) NULL COMMENT 'Original ID from spreadsheet e.g. MON-001',
    `category_id` INT UNSIGNED NOT NULL,
    `item_name` VARCHAR(150) NOT NULL,
    `brand` VARCHAR(80) NULL,
    `model` VARCHAR(120) NULL,
    `serial_number` VARCHAR(120) NULL,
    `quantity` INT UNSIGNED NOT NULL DEFAULT 1,
    
    -- Dual Currency & Financial Details
    `purchase_currency` VARCHAR(3) NOT NULL DEFAULT 'NGN' COMMENT 'Currency used at purchase (e.g. NGN, USD, GBP, EUR)',
    `purchase_cost` DECIMAL(12,2) NULL COMMENT 'Original purchase cost in purchase_currency',
    `secondary_currency` VARCHAR(3) NOT NULL DEFAULT 'USD' COMMENT 'Alternate reporting currency',
    `secondary_cost` DECIMAL(12,2) NULL COMMENT 'Cost converted or recorded in secondary currency',
    `exchange_rate` DECIMAL(12,4) NULL COMMENT 'Exchange rate applied at purchase (Primary / Secondary)',
    
    `purchase_date` DATE NULL,
    `supplier_id` INT UNSIGNED NULL,
    `current_location_id` INT UNSIGNED NOT NULL,
    `current_holder_id` INT UNSIGNED NULL COMMENT 'Staff member currently holding the asset',
    `condition_status` ENUM('new', 'good', 'fair', 'damaged') NOT NULL DEFAULT 'good',
    `lifecycle_status` ENUM('registered', 'available', 'assigned', 'in_repair', 'lost', 'damaged', 'disposed') NOT NULL DEFAULT 'available',
    `warranty_expiry_date` DATE NULL,
    `last_maintenance_date` DATE NULL,
    `next_maintenance_date` DATE NULL,
    `notes` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX `idx_asset_product` (`product_id`),
    INDEX `idx_asset_tag` (`asset_tag`),
    INDEX `idx_asset_serial` (`serial_number`),
    INDEX `idx_asset_category` (`category_id`),
    INDEX `idx_asset_lifecycle` (`lifecycle_status`),
    INDEX `idx_asset_holder` (`current_holder_id`),
    INDEX `idx_asset_location` (`current_location_id`),
    INDEX `idx_asset_supplier` (`supplier_id`),
    INDEX `idx_asset_warranty` (`warranty_expiry_date`),
    INDEX `idx_asset_maintenance` (`next_maintenance_date`),
    
    CONSTRAINT `fk_asset_product` FOREIGN KEY (`product_id`) REFERENCES `product_models` (`id`) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT `fk_asset_category` FOREIGN KEY (`category_id`) REFERENCES `asset_categories` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_asset_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT `fk_asset_location` FOREIGN KEY (`current_location_id`) REFERENCES `locations` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_asset_holder` FOREIGN KEY (`current_holder_id`) REFERENCES `employees` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 9b. INVENTORY STOCK (BULK INVENTORY BALANCES PER LOCATION)
-- Tracks WHERE and HOW MANY un-serialized units are stored per location.
-- Unambiguous Quantity Invariant:
-- Total Owned = quantity_available + quantity_assigned + quantity_damaged + quantity_under_repair
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `inventory_stock`;
CREATE TABLE `inventory_stock` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT UNSIGNED NOT NULL,
    `location_id` INT UNSIGNED NOT NULL,
    `quantity_available` INT UNSIGNED NOT NULL DEFAULT 0,
    `quantity_assigned` INT UNSIGNED NOT NULL DEFAULT 0,
    `quantity_damaged` INT UNSIGNED NOT NULL DEFAULT 0,
    `quantity_under_repair` INT UNSIGNED NOT NULL DEFAULT 0,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY `uk_stock_prod_loc` (`product_id`, `location_id`),
    INDEX `idx_stock_product` (`product_id`),
    INDEX `idx_stock_location` (`location_id`),
    
    CONSTRAINT `fk_stock_product` FOREIGN KEY (`product_id`) REFERENCES `product_models` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_stock_location` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 9c. INVENTORY TRANSACTIONS (IMMUTABLE AUDIT LEDGER)
-- Permanent historical log for every bulk stock balance change.
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `inventory_transactions`;
CREATE TABLE `inventory_transactions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `transaction_code` VARCHAR(50) NOT NULL UNIQUE,
    `product_id` INT UNSIGNED NOT NULL,
    `from_location_id` INT UNSIGNED NULL,
    `to_location_id` INT UNSIGNED NULL,
    `transaction_type` ENUM(
        'intake', 'checkout', 'return', 'transfer', 'adjustment', 'damage', 'loss', 'disposal',
        'purchase_receive', 'staff_checkout', 'staff_return', 'cycle_count_adjustment', 'damage_write_off', 'location_transfer'
    ) NOT NULL,
    `quantity` INT NOT NULL COMMENT 'Absolute or signed quantity affected',
    `unit_cost` DECIMAL(12,2) NULL COMMENT 'Unit purchase or valuation cost at transaction time',
    `currency` VARCHAR(3) NOT NULL DEFAULT 'NGN',
    `reference_type` VARCHAR(50) NULL COMMENT 'e.g. asset_assignment, po, adjustment',
    `reference_id` INT UNSIGNED NULL,
    `created_by_user_id` INT UNSIGNED NULL,
    `performed_by_user_id` INT UNSIGNED NULL,
    `notes` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX `idx_it_product` (`product_id`),
    INDEX `idx_it_type` (`transaction_type`),
    INDEX `idx_it_created` (`created_at`),
    INDEX `fk_it_user` (`performed_by_user_id`),
    
    CONSTRAINT `fk_it_product` FOREIGN KEY (`product_id`) REFERENCES `product_models` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_it_user` FOREIGN KEY (`performed_by_user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 10. ASSET ASSIGNMENTS (CHECKOUT / RETURN HISTORY)
-- Tracks custody of BOTH serialized assets AND bulk products.
-- For serialized: asset_id is set, quantity = 1.
-- For bulk: asset_id is NULL, product_id and source_location_id are set, quantity >= 1.
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `asset_assignments`;
CREATE TABLE `asset_assignments` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `assignment_code` VARCHAR(50) NOT NULL UNIQUE,
    `asset_id` INT UNSIGNED NULL COMMENT 'Null for bulk quantity assignments',
    `product_id` INT UNSIGNED NULL COMMENT 'Required for bulk assignments; populated for all',
    `source_location_id` INT UNSIGNED NULL COMMENT 'Location stock was drawn from for bulk checkout',
    `quantity` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Number of units assigned',
    `quantity_returned` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of units returned so far',
    `employee_id` INT UNSIGNED NOT NULL,
    `assigned_date` DATE NOT NULL,
    `assigned_by_user_id` INT UNSIGNED NOT NULL,
    `condition_at_issue` ENUM('new', 'good', 'fair') NOT NULL DEFAULT 'good',
    `agreement_signed` TINYINT(1) NOT NULL DEFAULT 0,
    `agreement_date` DATE NULL,
    `returned_date` DATE NULL,
    `returned_to_user_id` INT UNSIGNED NULL,
    `condition_at_return` ENUM('new', 'good', 'fair', 'damaged') NULL,
    `return_reason` VARCHAR(120) NULL,
    `status` ENUM('active', 'returned', 'partially_returned', 'transferred') NOT NULL DEFAULT 'active',
    `remarks` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX `idx_asg_asset` (`asset_id`),
    INDEX `idx_asg_product` (`product_id`),
    INDEX `idx_asg_employee` (`employee_id`),
    INDEX `idx_asg_status` (`status`),
    INDEX `idx_asg_dates` (`assigned_date`, `returned_date`),
    
    CONSTRAINT `fk_asg_asset` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_asg_product` FOREIGN KEY (`product_id`) REFERENCES `product_models` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_asg_source_loc` FOREIGN KEY (`source_location_id`) REFERENCES `locations` (`id`) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT `fk_asg_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_asg_assigned_by` FOREIGN KEY (`assigned_by_user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_asg_returned_to` FOREIGN KEY (`returned_to_user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 11. ASSET MOVEMENTS (CUSTODY & LOCATION LEDGER)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `asset_movements`;
CREATE TABLE `asset_movements` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `asset_id` INT UNSIGNED NOT NULL,
    `from_location_id` INT UNSIGNED NULL,
    `to_location_id` INT UNSIGNED NOT NULL,
    `from_employee_id` INT UNSIGNED NULL,
    `to_employee_id` INT UNSIGNED NULL,
    `moved_by_user_id` INT UNSIGNED NOT NULL,
    `movement_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `reason` VARCHAR(120) NOT NULL,
    `notes` TEXT NULL,
    
    INDEX `idx_mov_asset` (`asset_id`),
    INDEX `idx_mov_date` (`movement_date`),
    
    CONSTRAINT `fk_mov_asset` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_mov_from_loc` FOREIGN KEY (`from_location_id`) REFERENCES `locations` (`id`) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT `fk_mov_to_loc` FOREIGN KEY (`to_location_id`) REFERENCES `locations` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_mov_from_emp` FOREIGN KEY (`from_employee_id`) REFERENCES `employees` (`id`) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT `fk_mov_to_emp` FOREIGN KEY (`to_employee_id`) REFERENCES `employees` (`id`) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT `fk_mov_user` FOREIGN KEY (`moved_by_user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 12. ASSET MAINTENANCE & REPAIRS
-- Supports dual currency for repair costs
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `asset_maintenance`;
CREATE TABLE `asset_maintenance` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `maintenance_code` VARCHAR(50) NOT NULL UNIQUE,
    `asset_id` INT UNSIGNED NOT NULL,
    `maintenance_type` ENUM('preventative', 'corrective', 'upgrade', 'inspection') NOT NULL DEFAULT 'preventative',
    `scheduled_date` DATE NOT NULL,
    `completed_date` DATE NULL,
    `performed_by_type` ENUM('internal', 'vendor') NOT NULL DEFAULT 'internal',
    `supplier_id` INT UNSIGNED NULL,
    `technician_name` VARCHAR(100) NULL,
    
    -- Dual currency maintenance cost
    `currency` VARCHAR(3) NOT NULL DEFAULT 'NGN',
    `cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `secondary_currency` VARCHAR(3) NOT NULL DEFAULT 'USD',
    `secondary_cost` DECIMAL(12,2) NULL,
    
    `status` ENUM('scheduled', 'in_progress', 'completed', 'cancelled') NOT NULL DEFAULT 'scheduled',
    `issue_description` TEXT NULL,
    `actions_taken` TEXT NULL,
    `notes` TEXT NULL,
    `created_by_user_id` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX `idx_maint_asset` (`asset_id`),
    INDEX `idx_maint_status` (`status`),
    INDEX `idx_maint_scheduled` (`scheduled_date`),
    
    CONSTRAINT `fk_maint_asset` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_maint_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT `fk_maint_user` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 13. ONBOARDING WORKFLOW
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `onboarding_requests`;
CREATE TABLE `onboarding_requests` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `request_code` VARCHAR(50) NOT NULL UNIQUE,
    `employee_id` INT UNSIGNED NOT NULL,
    `target_start_date` DATE NOT NULL,
    `bundle_name` VARCHAR(80) NOT NULL DEFAULT 'Standard Staff Bundle',
    `status` ENUM('pending', 'in_progress', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
    `completed_date` DATE NULL,
    `created_by_user_id` INT UNSIGNED NOT NULL,
    `notes` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    CONSTRAINT `fk_onb_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_onb_user` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `onboarding_items`;
CREATE TABLE `onboarding_items` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `onboarding_request_id` INT UNSIGNED NOT NULL,
    `category_id` INT UNSIGNED NOT NULL,
    `asset_id` INT UNSIGNED NULL,
    `is_required` TINYINT(1) NOT NULL DEFAULT 1,
    `is_fulfilled` TINYINT(1) NOT NULL DEFAULT 0,
    `fulfilled_date` DATE NULL,
    `notes` VARCHAR(255) NULL,
    
    CONSTRAINT `fk_onbi_req` FOREIGN KEY (`onboarding_request_id`) REFERENCES `onboarding_requests` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_onbi_cat` FOREIGN KEY (`category_id`) REFERENCES `asset_categories` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_onbi_asset` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 14. OFFBOARDING & EXIT CLEARANCE WORKFLOW
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `offboarding_requests`;
CREATE TABLE `offboarding_requests` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `request_code` VARCHAR(50) NOT NULL UNIQUE,
    `employee_id` INT UNSIGNED NOT NULL,
    `initiation_date` DATE NOT NULL,
    `target_exit_date` DATE NOT NULL,
    `clearance_status` ENUM('pending', 'cleared', 'not_cleared', 'disputed') NOT NULL DEFAULT 'pending',
    `cleared_by_user_id` INT UNSIGNED NULL,
    `cleared_date` DATE NULL,
    `remarks` TEXT NULL,
    `created_by_user_id` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX `idx_offb_status` (`clearance_status`),
    INDEX `idx_offb_emp` (`employee_id`),
    
    CONSTRAINT `fk_offb_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_offb_cleared_by` FOREIGN KEY (`cleared_by_user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT `fk_offb_created_by` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `offboarding_items`;
CREATE TABLE `offboarding_items` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `offboarding_request_id` INT UNSIGNED NOT NULL,
    `asset_id` INT UNSIGNED NOT NULL,
    `assignment_id` INT UNSIGNED NOT NULL,
    `expected_condition` ENUM('new', 'good', 'fair') NOT NULL DEFAULT 'good',
    `return_status` ENUM('pending', 'returned_intact', 'returned_damaged', 'lost', 'waived') NOT NULL DEFAULT 'pending',
    `returned_condition` ENUM('new', 'good', 'fair', 'damaged') NULL,
    `damage_cost_assessed` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `damage_cost_currency` VARCHAR(3) NOT NULL DEFAULT 'NGN',
    `inspected_by_user_id` INT UNSIGNED NULL,
    `inspected_date` DATE NULL,
    `inspection_notes` TEXT NULL,
    
    CONSTRAINT `fk_offbi_req` FOREIGN KEY (`offboarding_request_id`) REFERENCES `offboarding_requests` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_offbi_asset` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_offbi_asg` FOREIGN KEY (`assignment_id`) REFERENCES `asset_assignments` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_offbi_inspector` FOREIGN KEY (`inspected_by_user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 15. ASSET AGREEMENTS & DOCUMENTS
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `asset_agreements`;
CREATE TABLE `asset_agreements` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `assignment_id` INT UNSIGNED NOT NULL,
    `employee_id` INT UNSIGNED NOT NULL,
    `agreement_type` ENUM('custody_policy', 'handover_acceptance', 'remote_work_policy') NOT NULL DEFAULT 'custody_policy',
    `agreement_status` ENUM('pending', 'signed', 'rejected', 'expired') NOT NULL DEFAULT 'pending',
    `signed_date` DATETIME NULL,
    `document_path` VARCHAR(255) NULL,
    `notes` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    CONSTRAINT `fk_agr_asg` FOREIGN KEY (`assignment_id`) REFERENCES `asset_assignments` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_agr_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 16. AUDIT LOGS (IMMUTABLE TIMELINE)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `audit_logs`;
CREATE TABLE `audit_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NULL,
    `user_name` VARCHAR(100) NOT NULL DEFAULT 'System',
    `action` VARCHAR(60) NOT NULL,
    `entity_type` VARCHAR(60) NOT NULL,
    `entity_id` INT UNSIGNED NOT NULL,
    `old_values` JSON NULL,
    `new_values` JSON NULL,
    `ip_address` VARCHAR(45) NOT NULL DEFAULT '127.0.0.1',
    `user_agent` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX `idx_audit_entity` (`entity_type`, `entity_id`),
    INDEX `idx_audit_action` (`action`),
    INDEX `idx_audit_user` (`user_id`),
    INDEX `idx_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- MASTER SEED DATA
-- =====================================================================

-- System Settings (Including Dual Currency Configuration)
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `setting_group`, `description`) VALUES
('app_name', 'AssetVault', 'general', 'Organization Asset Management System Name'),
('company_name', 'Baye Business Solutions', 'general', 'Corporate Entity Name'),
('brand_color', '#12b1b0', 'ui', 'Primary Brand Accent Color'),
('base_currency', 'NGN', 'financial', 'Primary Base Currency Code'),
('base_currency_symbol', '₦', 'financial', 'Primary Base Currency Symbol'),
('secondary_currency', 'USD', 'financial', 'Secondary Dual Currency Code'),
('secondary_currency_symbol', '$', 'financial', 'Secondary Currency Symbol'),
('default_exchange_rate', '1500.0000', 'financial', 'Default NGN to USD Exchange Rate (1 USD = NGN)'),
('dual_currency_enabled', '1', 'financial', 'Enable dual currency display across assets and reports (1 = Yes, 0 = No)'),
('warranty_alert_days', '30', 'alerts', 'Days before warranty expiration to trigger dashboard alert'),
('maintenance_alert_days', '14', 'alerts', 'Days before scheduled maintenance to trigger alert');

-- Departments
INSERT INTO `departments` (`code`, `name`, `description`) VALUES
('TECH', 'Tech', 'Software Engineering, DevOps, and Technical Support'),
('MKT', 'Sales & Marketing', 'Marketing Campaigns, Business Development and Sales'),
('ADM', 'Admin', 'Operations, Facilities, Human Resources and Administration'),
('EXEC', 'Executive', 'Executive Leadership and Strategic Direction');

-- Locations (Direct from workbook + lists reference)
INSERT INTO `locations` (`code`, `name`, `building`, `floor`, `description`) VALUES
('LOC-OPEN', 'Open Office', 'Main Office', 'Ground Floor', 'General open workspace'),
('LOC-SALES', 'Sales Office', 'Main Office', 'Ground Floor', 'Dedicated sales team workspace'),
('LOC-MKT', 'Marketing Office', 'Main Office', '1st Floor', 'Marketing department creative office'),
('LOC-RECEP', 'Reception', 'Main Office', 'Ground Floor', 'Front desk and visitor greeting area'),
('LOC-CONF', 'Conference Room', 'Main Office', '1st Floor', 'Main executive meeting and board room'),
('LOC-CEO', 'CEO Office', 'Main Office', '1st Floor', 'Executive suite for the Chief Executive Officer'),
('LOC-STORE', 'Store', 'Main Office', 'Ground Floor', 'Secure IT hardware inventory store'),
('LOC-KIT', 'Kitchen', 'Main Office', 'Ground Floor', 'Staff pantry and break room'),
('LOC-ENT', 'Entrance', 'Main Office', 'Ground Floor', 'Main building access foyer'),
('LOC-CORR', 'Corridor', 'Main Office', 'Ground Floor', 'Inter-office hallway transit zone');

-- Asset Categories (All 14 from workbook Lists sheet with serialization rules)
INSERT INTO `asset_categories` (`code`, `name`, `is_serialized`, `default_maintenance_interval_days`, `description`) VALUES
('LAP', 'Laptop', 1, 180, 'Portable workstation notebooks (requires serial number)'),
('MON', 'Monitor', 1, 365, 'External display screens (requires serial number)'),
('MOU', 'Mouse', 0, NULL, 'Optical/wireless mice (consumable / quantity tracked)'),
('KBD', 'Keyboard', 0, NULL, 'Wired/wireless keyboards (bulk consumable)'),
('IDC', 'ID Card', 0, NULL, 'Physical staff identity cards and RFID access badges'),
('PHN', 'Phone', 1, 180, 'Company mobile smartphones and VoIP handsets'),
('PRN', 'Printer', 1, 90, 'Network and multifunction document printers'),
('UPS', 'UPS', 1, 180, 'Uninterruptible Power Supply backup battery systems'),
('RTR', 'Router', 1, 180, 'Edge routers, switches and network gateway hardware'),
('TAB', 'Tablet', 1, 180, 'iPad / Android mobile slate tablets'),
('HDS', 'Headset', 0, NULL, 'Audio call headsets for meetings and support'),
('CHR', 'Chair', 0, NULL, 'Ergonomic office desk chairs'),
('DSK', 'Desk', 0, NULL, 'Workstations and conference office desks'),
('OTH', 'Other', 0, NULL, 'Miscellaneous company assets and accessories');

-- Roles
INSERT INTO `roles` (`name`, `slug`, `description`) VALUES
('Super Administrator', 'super_admin', 'Unrestricted administrative access to all system modules, configurations, and audit logs'),
('Asset Manager', 'asset_admin', 'Full control over assets, assignments, maintenance, inventory, and staff onboarding/offboarding'),
('Department Manager', 'manager', 'View departmental assets, approve employee assignments, and participate in offboarding clearance'),
('Staff Custodian', 'staff', 'Read-only access to personal assigned assets, pending agreements, and fault reporting');

-- Permissions Catalog
INSERT INTO `permissions` (`module`, `name`, `slug`, `description`) VALUES
('assets', 'View Assets', 'assets.view', 'Search, browse, and inspect asset details'),
('assets', 'Create Assets', 'assets.create', 'Register new individual and bulk assets'),
('assets', 'Edit Assets', 'assets.edit', 'Modify asset specifications and records'),
('assets', 'Delete Assets', 'assets.delete', 'Archive or soft-delete asset records'),
('assets', 'Assign Assets', 'assets.assign', 'Check out available assets to employees'),
('assets', 'Return Assets', 'assets.return', 'Check in returned equipment and inspect condition'),
('assets', 'Transfer Assets', 'assets.transfer', 'Directly transfer asset custody or location'),
('assets', 'Dispose Assets', 'assets.dispose', 'Retire or write off company assets'),
('staff', 'View Staff', 'staff.view', 'View employee directory and asset profiles'),
('staff', 'Manage Staff', 'staff.manage', 'Add, update, or alter employee status'),
('maintenance', 'View Maintenance', 'maintenance.view', 'View maintenance schedules and repair logs'),
('maintenance', 'Create Maintenance', 'maintenance.create', 'Schedule service or record repairs'),
('maintenance', 'Update Maintenance', 'maintenance.update', 'Complete service tickets and update costs'),
('onboarding', 'View Onboarding', 'onboarding.view', 'View new hire asset fulfillment pipelines'),
('onboarding', 'Manage Onboarding', 'onboarding.manage', 'Create checklists and allocate asset bundles'),
('offboarding', 'View Offboarding', 'offboarding.view', 'View employee exit clearance queues'),
('offboarding', 'Clear Offboarding', 'offboarding.clear', 'Inspect returned assets and sign exit clearance'),
('reports', 'View Reports', 'reports.view', 'Access analytics dashboards and report modules'),
('reports', 'Export Reports', 'reports.export', 'Export asset registers to CSV or Excel'),
('catalog', 'View Product Catalog', 'catalog.view', 'Browse product catalog models and specs'),
('catalog', 'Manage Product Catalog', 'catalog.manage', 'Create and edit product catalog items'),
('inventory', 'View Inventory Stock', 'inventory.view', 'Inspect stock levels, balances, and history'),
('inventory', 'Manage Inventory Stock', 'inventory.manage', 'Perform receipts, transfers, adjustments, write-offs'),
('settings', 'Manage Settings', 'settings.manage', 'Configure categories, locations, exchange rates'),
('security', 'Manage Users', 'users.manage', 'Create system accounts, assign roles'),
('security', 'View Audit Logs', 'audit.view', 'Inspect immutable system event audit trail');

-- Bind Super Admin to ALL permissions
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 1, `id` FROM `permissions`;

-- Bind Asset Admin to asset operations, maintenance, onboarding, offboarding, reports
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 2, `id` FROM `permissions` WHERE `slug` NOT IN ('settings.manage', 'users.manage');

-- Bind Manager to view permissions + offboarding review
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 3, `id` FROM `permissions` WHERE `slug` IN ('assets.view', 'staff.view', 'maintenance.view', 'offboarding.view', 'reports.view');

-- Bind Staff Custodian to view own assets
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 4, `id` FROM `permissions` WHERE `slug` IN ('assets.view');

-- Initial Super Admin User (Default Password: Admin@123456)
-- Hash generated via password_hash('Admin@123456', PASSWORD_BCRYPT)
INSERT INTO `users` (`username`, `email`, `password_hash`, `full_name`, `role_id`, `is_active`) VALUES
('admin', 'admin@bayebusinesssolutions.com', '$2y$10$gQHb1MjxlcmVr4HxhKqYc.HXw34srd7rWaHL15Gdwkc5x8SqfURr2', 'System Administrator', 1, 1);
