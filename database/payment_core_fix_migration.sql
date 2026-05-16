-- Herald Canteen payment core database fix
-- Fixes COD/eSewa/Khalti checkout storage, KOT/invoice procedure compatibility,
-- and older database structures that caused payment.php?status=failed.

CREATE DATABASE IF NOT EXISTS `herald_canteen` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `herald_canteen`;

SET FOREIGN_KEY_CHECKS = 0;

DROP PROCEDURE IF EXISTS `hc_add_column_if_missing`;
DROP PROCEDURE IF EXISTS `hc_add_index_if_missing`;

DELIMITER //
CREATE PROCEDURE `hc_add_column_if_missing`(
    IN p_table VARCHAR(64),
    IN p_column VARCHAR(64),
    IN p_sql TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table
          AND COLUMN_NAME = p_column
    ) THEN
        SET @hc_sql = p_sql;
        PREPARE hc_stmt FROM @hc_sql;
        EXECUTE hc_stmt;
        DEALLOCATE PREPARE hc_stmt;
    END IF;
END//

CREATE PROCEDURE `hc_add_index_if_missing`(
    IN p_table VARCHAR(64),
    IN p_index VARCHAR(64),
    IN p_sql TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table
          AND INDEX_NAME = p_index
    ) THEN
        SET @hc_sql = p_sql;
        PREPARE hc_stmt FROM @hc_sql;
        EXECUTE hc_stmt;
        DEALLOCATE PREPARE hc_stmt;
    END IF;
END//
DELIMITER ;

-- ---------------------------------------------------------------------------
-- Delivery locations
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `delivery_locations` (
  `location_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `location_name` VARCHAR(150) NOT NULL,
  `block_name` VARCHAR(100) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`location_id`),
  UNIQUE KEY `uq_location_block` (`location_name`, `block_name`),
  KEY `idx_dl_active_sort` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------------
-- Orders: full checkout breakdown + location + idempotency token
-- ---------------------------------------------------------------------------
SET @hc_sql = 'ALTER TABLE `orders` ADD COLUMN `subtotal_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `user_id`';
CALL hc_add_column_if_missing('orders', 'subtotal_amount', @hc_sql);

SET @hc_sql = 'ALTER TABLE `orders` ADD COLUMN `delivery_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `subtotal_amount`';
CALL hc_add_column_if_missing('orders', 'delivery_fee', @hc_sql);

SET @hc_sql = 'ALTER TABLE `orders` ADD COLUMN `delivery_mode` ENUM(''delivery'',''takeaway'') NOT NULL DEFAULT ''delivery'' AFTER `payment_method`';
CALL hc_add_column_if_missing('orders', 'delivery_mode', @hc_sql);

SET @hc_sql = 'ALTER TABLE `orders` ADD COLUMN `delivery_location_id` INT UNSIGNED NULL DEFAULT NULL AFTER `delivery_mode`';
CALL hc_add_column_if_missing('orders', 'delivery_location_id', @hc_sql);

SET @hc_sql = 'ALTER TABLE `orders` ADD COLUMN `delivery_location_name` VARCHAR(150) NULL DEFAULT NULL AFTER `delivery_location_id`';
CALL hc_add_column_if_missing('orders', 'delivery_location_name', @hc_sql);

SET @hc_sql = 'ALTER TABLE `orders` ADD COLUMN `delivery_block_name` VARCHAR(100) NULL DEFAULT NULL AFTER `delivery_location_name`';
CALL hc_add_column_if_missing('orders', 'delivery_block_name', @hc_sql);

SET @hc_sql = 'ALTER TABLE `orders` ADD COLUMN `special_notes` VARCHAR(500) NULL DEFAULT NULL AFTER `delivery_block_name`';
CALL hc_add_column_if_missing('orders', 'special_notes', @hc_sql);

SET @hc_sql = 'ALTER TABLE `orders` ADD COLUMN `checkout_token` VARCHAR(64) NULL DEFAULT NULL AFTER `special_notes`';
CALL hc_add_column_if_missing('orders', 'checkout_token', @hc_sql);

SET @hc_sql = 'CREATE UNIQUE INDEX `uq_orders_checkout_token` ON `orders` (`checkout_token`)';
CALL hc_add_index_if_missing('orders', 'uq_orders_checkout_token', @hc_sql);

SET @hc_sql = 'CREATE INDEX `idx_orders_delivery_location` ON `orders` (`delivery_location_id`)';
CALL hc_add_index_if_missing('orders', 'idx_orders_delivery_location', @hc_sql);

-- Backfill old orders so reports/invoices do not show subtotal as zero.
UPDATE `orders` o
JOIN (
    SELECT `order_id`, ROUND(SUM(`quantity` * `price`), 2) AS item_subtotal
    FROM `order_items`
    GROUP BY `order_id`
) s ON s.`order_id` = o.`order_id`
SET
    o.`subtotal_amount` = CASE WHEN o.`subtotal_amount` = 0 THEN s.`item_subtotal` ELSE o.`subtotal_amount` END,
    o.`delivery_fee` = CASE
        WHEN o.`delivery_fee` = 0 AND o.`total_amount` >= s.`item_subtotal`
        THEN ROUND(o.`total_amount` - s.`item_subtotal`, 2)
        ELSE o.`delivery_fee`
    END;

-- ---------------------------------------------------------------------------
-- Payments: COD should not carry eSewa UUIDs; transaction_uuid is for gateways.
-- ---------------------------------------------------------------------------
SET @hc_sql = 'ALTER TABLE `payments` ADD COLUMN `transaction_uuid` VARCHAR(100) NULL DEFAULT NULL AFTER `order_id`';
CALL hc_add_column_if_missing('payments', 'transaction_uuid', @hc_sql);

SET @hc_sql = 'ALTER TABLE `payments` ADD COLUMN `gateway_ref` VARCHAR(255) NULL DEFAULT NULL AFTER `amount`';
CALL hc_add_column_if_missing('payments', 'gateway_ref', @hc_sql);

SET @hc_sql = 'ALTER TABLE `payments` ADD COLUMN `paid_at` DATETIME NULL DEFAULT NULL AFTER `gateway_ref`';
CALL hc_add_column_if_missing('payments', 'paid_at', @hc_sql);

UPDATE `payments`
SET `transaction_uuid` = NULL
WHERE `payment_method` = 'cod';

SET @hc_sql = 'CREATE INDEX `idx_payments_transaction_uuid` ON `payments` (`transaction_uuid`)';
CALL hc_add_index_if_missing('payments', 'idx_payments_transaction_uuid', @hc_sql);

SET @hc_sql = 'CREATE INDEX `idx_payments_order_status` ON `payments` (`order_id`, `payment_status`)';
CALL hc_add_index_if_missing('payments', 'idx_payments_order_status', @hc_sql);

-- ---------------------------------------------------------------------------
-- Kitchen Order Tickets: make the table compatible with both old and new code.
-- The earlier failing migration created a procedure that inserted user_id even
-- though many existing DBs did not have that column. This migration fixes that.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `kitchen_order_tickets` (
  `kot_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NULL DEFAULT NULL,
  `kot_status` ENUM('active','archived','completed','cancelled') NOT NULL DEFAULT 'active',
  `delivery_mode` ENUM('delivery','takeaway') NOT NULL DEFAULT 'delivery',
  `special_notes` VARCHAR(500) NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `archived_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`kot_id`),
  UNIQUE KEY `uq_kot_order` (`order_id`),
  KEY `idx_kot_status` (`kot_status`),
  KEY `idx_kot_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET @hc_sql = 'ALTER TABLE `kitchen_order_tickets` ADD COLUMN `user_id` INT UNSIGNED NULL DEFAULT NULL AFTER `order_id`';
CALL hc_add_column_if_missing('kitchen_order_tickets', 'user_id', @hc_sql);


SET @hc_sql = 'ALTER TABLE `kitchen_order_tickets` ADD COLUMN `kot_status` ENUM(''active'',''archived'',''completed'',''cancelled'') NOT NULL DEFAULT ''active'' AFTER `user_id`';
CALL hc_add_column_if_missing('kitchen_order_tickets', 'kot_status', @hc_sql);

ALTER TABLE `kitchen_order_tickets`
  MODIFY COLUMN `kot_status` ENUM('active','archived','completed','cancelled') NOT NULL DEFAULT 'active';

SET @hc_sql = 'ALTER TABLE `kitchen_order_tickets` ADD COLUMN `delivery_mode` ENUM(''delivery'',''takeaway'') NOT NULL DEFAULT ''delivery'' AFTER `kot_status`';
CALL hc_add_column_if_missing('kitchen_order_tickets', 'delivery_mode', @hc_sql);

SET @hc_sql = 'ALTER TABLE `kitchen_order_tickets` ADD COLUMN `special_notes` VARCHAR(500) NULL DEFAULT NULL AFTER `delivery_mode`';
CALL hc_add_column_if_missing('kitchen_order_tickets', 'special_notes', @hc_sql);

SET @hc_sql = 'ALTER TABLE `kitchen_order_tickets` ADD COLUMN `archived_at` DATETIME NULL DEFAULT NULL AFTER `created_at`';
CALL hc_add_column_if_missing('kitchen_order_tickets', 'archived_at', @hc_sql);

SET @hc_sql = 'CREATE UNIQUE INDEX `uq_kot_order` ON `kitchen_order_tickets` (`order_id`)';
CALL hc_add_index_if_missing('kitchen_order_tickets', 'uq_kot_order', @hc_sql);

SET @hc_sql = 'CREATE INDEX `idx_kot_status` ON `kitchen_order_tickets` (`kot_status`)';
CALL hc_add_index_if_missing('kitchen_order_tickets', 'idx_kot_status', @hc_sql);

SET @hc_sql = 'CREATE INDEX `idx_kot_user` ON `kitchen_order_tickets` (`user_id`)';
CALL hc_add_index_if_missing('kitchen_order_tickets', 'idx_kot_user', @hc_sql);

UPDATE `kitchen_order_tickets` k
JOIN `orders` o ON o.`order_id` = k.`order_id`
SET k.`user_id` = o.`user_id`
WHERE k.`user_id` IS NULL;

-- ---------------------------------------------------------------------------
-- KOT invoices
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `kot_invoices` (
  `invoice_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `invoice_token` CHAR(64) NOT NULL,
  `is_paid` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `paid_at` DATETIME NULL DEFAULT NULL,
  `downloaded_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`invoice_id`),
  UNIQUE KEY `uq_invoice_order` (`order_id`),
  UNIQUE KEY `uq_invoice_token` (`invoice_token`),
  KEY `idx_invoice_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET @hc_sql = 'ALTER TABLE `kot_invoices` ADD COLUMN `paid_at` DATETIME NULL DEFAULT NULL AFTER `created_at`';
CALL hc_add_column_if_missing('kot_invoices', 'paid_at', @hc_sql);

SET @hc_sql = 'ALTER TABLE `kot_invoices` ADD COLUMN `downloaded_at` DATETIME NULL DEFAULT NULL AFTER `paid_at`';
CALL hc_add_column_if_missing('kot_invoices', 'downloaded_at', @hc_sql);

-- Ensure every existing order has a KOT and invoice.
INSERT INTO `kitchen_order_tickets`
    (`order_id`, `user_id`, `kot_status`, `delivery_mode`, `special_notes`, `created_at`)
SELECT
    o.`order_id`,
    o.`user_id`,
    CASE WHEN o.`status` IN ('ready','out_for_delivery','delivered','cancelled') THEN 'archived' ELSE 'active' END,
    COALESCE(o.`delivery_mode`, 'delivery'),
    o.`special_notes`,
    o.`created_at`
FROM `orders` o
WHERE NOT EXISTS (
    SELECT 1 FROM `kitchen_order_tickets` k WHERE k.`order_id` = o.`order_id`
);

INSERT INTO `kot_invoices`
    (`order_id`, `user_id`, `invoice_token`, `is_paid`, `created_at`, `paid_at`)
SELECT
    o.`order_id`,
    o.`user_id`,
    SHA2(CONCAT(UUID(), '-', o.`order_id`, '-', o.`user_id`, '-', RAND()), 256),
    CASE WHEN EXISTS (
        SELECT 1 FROM `payments` p
        WHERE p.`order_id` = o.`order_id` AND p.`payment_status` = 'successful'
    ) THEN 1 ELSE 0 END,
    o.`created_at`,
    (
        SELECT MAX(p2.`paid_at`)
        FROM `payments` p2
        WHERE p2.`order_id` = o.`order_id` AND p2.`payment_status` = 'successful'
    )
FROM `orders` o
WHERE NOT EXISTS (
    SELECT 1 FROM `kot_invoices` ki WHERE ki.`order_id` = o.`order_id`
);

UPDATE `kot_invoices` ki
JOIN `payments` p ON p.`order_id` = ki.`order_id` AND p.`payment_status` = 'successful'
SET ki.`is_paid` = 1,
    ki.`paid_at` = COALESCE(ki.`paid_at`, p.`paid_at`, NOW());

-- ---------------------------------------------------------------------------
-- Stored procedure used by COD/eSewa/Khalti handlers
-- ---------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS `create_kot_and_invoice`;
DELIMITER //
CREATE PROCEDURE `create_kot_and_invoice`(
    IN p_order_id INT UNSIGNED,
    IN p_user_id INT UNSIGNED,
    IN p_delivery_mode VARCHAR(20),
    IN p_special_notes VARCHAR(500)
)
BEGIN
    DECLARE v_token CHAR(64);
    SET v_token = SHA2(CONCAT(UUID(), '-', p_order_id, '-', p_user_id, '-', RAND()), 256);

    INSERT INTO `kitchen_order_tickets`
        (`order_id`, `user_id`, `kot_status`, `delivery_mode`, `special_notes`)
    VALUES
        (p_order_id,
         p_user_id,
         'active',
         CASE WHEN p_delivery_mode IN ('delivery','takeaway') THEN p_delivery_mode ELSE 'delivery' END,
         p_special_notes)
    ON DUPLICATE KEY UPDATE
        `user_id` = VALUES(`user_id`),
        `delivery_mode` = VALUES(`delivery_mode`),
        `special_notes` = VALUES(`special_notes`),
        `kot_status` = 'active';

    INSERT INTO `kot_invoices`
        (`order_id`, `user_id`, `invoice_token`, `is_paid`)
    SELECT p_order_id, p_user_id, v_token, 0
    FROM DUAL
    WHERE NOT EXISTS (
        SELECT 1 FROM `kot_invoices` WHERE `order_id` = p_order_id
    );
END//
DELIMITER ;

-- Keep active kitchen tickets only while the order is still with chef.
DROP TRIGGER IF EXISTS `trg_archive_kot_on_ready`;
DELIMITER //
CREATE TRIGGER `trg_archive_kot_on_ready`
AFTER UPDATE ON `orders`
FOR EACH ROW
BEGIN
    IF NEW.`status` IN ('ready', 'out_for_delivery', 'delivered', 'cancelled')
       AND OLD.`status` NOT IN ('ready', 'out_for_delivery', 'delivered', 'cancelled') THEN
        UPDATE `kitchen_order_tickets`
        SET `kot_status` = 'archived',
            `archived_at` = COALESCE(`archived_at`, NOW())
        WHERE `order_id` = NEW.`order_id`
          AND `kot_status` = 'active';
    END IF;
END//
DELIMITER ;

-- Seed delivery locations used by the checkout page.
INSERT IGNORE INTO `delivery_locations`
  (`location_name`, `block_name`, `is_active`, `sort_order`)
VALUES
  ('IT & NOC', 'WLV Block', 1, 10),
  ('RTE (Registry, Timetable & Examination)', 'WLV Block', 1, 20),
  ('Finance', 'WLV Block', 1, 30),
  ('WLV SSD', 'WLV Block', 1, 40),
  ('WLV PAT', 'WLV Block', 1, 50),
  ('Academics A', 'WLV Block', 1, 60),
  ('Academics B', 'WLV Block', 1, 70),
  ('CEO Office', 'WLV Block', 1, 80),
  ('ING SSD', 'ING Block', 1, 110),
  ('ING PAT', 'ING Block', 1, 120),
  ('PATIO', 'ING Block', 1, 130),
  ('Library', 'Library Block', 1, 210),
  ('IMBA Lounge', 'Library Block', 1, 220),
  ('AD (Admission Department)', 'Library Block', 1, 230),
  ('BD (Business Department)', 'HCK Block', 1, 310),
  ('HR (Human Resource)', 'Resource Block', 1, 410),
  ('Academics C', 'Resource Block', 1, 420),
  ('Academics D', 'Resource Block', 1, 430),
  ('Academics E', 'Resource Block', 1, 440),
  ('Academics F', 'Resource Block', 1, 450),
  ('Academics G', 'Resource Block', 1, 460),
  ('Academics H', 'Resource Block', 1, 470),
  ('New Academics', 'Resource Block', 1, 480);

DROP PROCEDURE IF EXISTS `hc_add_column_if_missing`;
DROP PROCEDURE IF EXISTS `hc_add_index_if_missing`;

SET FOREIGN_KEY_CHECKS = 1;
