-- ============================================================
-- Herald Canteen — Payment / Checkout Fix Migration
-- MySQL + MariaDB safe version: no ALTER COLUMN IF NOT EXISTS.
-- Run this once after importing herald_canteen.sql on an existing database.
-- Safe to re-run.
-- ============================================================

-- ---------- Helper procedures for MySQL-safe conditional DDL ----------
DROP PROCEDURE IF EXISTS hc_add_column_if_missing;
DELIMITER //
CREATE PROCEDURE hc_add_column_if_missing(
    IN p_table_name VARCHAR(64),
    IN p_column_name VARCHAR(64),
    IN p_ddl TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table_name
          AND COLUMN_NAME = p_column_name
    ) THEN
        SET @hc_sql = p_ddl;
        PREPARE hc_stmt FROM @hc_sql;
        EXECUTE hc_stmt;
        DEALLOCATE PREPARE hc_stmt;
    END IF;
END//
DELIMITER ;

DROP PROCEDURE IF EXISTS hc_add_index_if_missing;
DELIMITER //
CREATE PROCEDURE hc_add_index_if_missing(
    IN p_table_name VARCHAR(64),
    IN p_index_name VARCHAR(64),
    IN p_ddl TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table_name
          AND INDEX_NAME = p_index_name
    ) THEN
        SET @hc_sql = p_ddl;
        PREPARE hc_stmt FROM @hc_sql;
        EXECUTE hc_stmt;
        DEALLOCATE PREPARE hc_stmt;
    END IF;
END//
DELIMITER ;

-- ---------- Delivery locations ----------
CREATE TABLE IF NOT EXISTS `delivery_locations` (
  `location_id`   INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `location_name` VARCHAR(150)    NOT NULL,
  `block_name`    VARCHAR(100)    NOT NULL,
  `is_active`     TINYINT(1)      NOT NULL DEFAULT 1,
  `sort_order`    INT UNSIGNED    NOT NULL DEFAULT 0,
  `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME        NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`location_id`),
  UNIQUE KEY `uq_location_block` (`location_name`, `block_name`),
  KEY `idx_dl_active_sort` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------- Orders checkout columns ----------
SET @hc_sql = 'ALTER TABLE `orders` ADD COLUMN `delivery_mode` ENUM(''delivery'',''takeaway'') NOT NULL DEFAULT ''delivery'' AFTER `payment_method`';
CALL hc_add_column_if_missing('orders', 'delivery_mode', @hc_sql);

SET @hc_sql = 'ALTER TABLE `orders` ADD COLUMN `special_notes` VARCHAR(500) NULL DEFAULT NULL AFTER `delivery_mode`';
CALL hc_add_column_if_missing('orders', 'special_notes', @hc_sql);

SET @hc_sql = 'ALTER TABLE `orders` ADD COLUMN `delivery_location_id` INT UNSIGNED NULL DEFAULT NULL AFTER `special_notes`';
CALL hc_add_column_if_missing('orders', 'delivery_location_id', @hc_sql);

SET @hc_sql = 'ALTER TABLE `orders` ADD COLUMN `delivery_location_name` VARCHAR(150) NULL DEFAULT NULL AFTER `delivery_location_id`';
CALL hc_add_column_if_missing('orders', 'delivery_location_name', @hc_sql);

SET @hc_sql = 'ALTER TABLE `orders` ADD COLUMN `delivery_block_name` VARCHAR(100) NULL DEFAULT NULL AFTER `delivery_location_name`';
CALL hc_add_column_if_missing('orders', 'delivery_block_name', @hc_sql);

SET @hc_sql = 'CREATE INDEX `idx_orders_delivery_location` ON `orders` (`delivery_location_id`)';
CALL hc_add_index_if_missing('orders', 'idx_orders_delivery_location', @hc_sql);

SET @hc_sql = 'CREATE INDEX `idx_payments_transaction_uuid` ON `payments` (`transaction_uuid`)';
CALL hc_add_index_if_missing('payments', 'idx_payments_transaction_uuid', @hc_sql);

-- ---------- KOT + invoice tables used by payment success/COD flow ----------
CREATE TABLE IF NOT EXISTS `kitchen_order_tickets` (
  `kot_id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`      INT UNSIGNED NOT NULL,
  `user_id`       INT UNSIGNED NOT NULL,
  `delivery_mode` ENUM('delivery','takeaway') NOT NULL DEFAULT 'delivery',
  `special_notes` VARCHAR(500) NULL DEFAULT NULL,
  `kot_status`    ENUM('active','completed','cancelled') NOT NULL DEFAULT 'active',
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`kot_id`),
  UNIQUE KEY `uq_kot_order` (`order_id`),
  KEY `idx_kot_status` (`kot_status`),
  KEY `idx_kot_user` (`user_id`),
  CONSTRAINT `fk_kot_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`order_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_kot_user`  FOREIGN KEY (`user_id`)  REFERENCES `users`  (`user_id`)  ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `kot_invoices` (
  `invoice_id`    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`      INT UNSIGNED NOT NULL,
  `user_id`       INT UNSIGNED NOT NULL,
  `invoice_token` CHAR(64) NOT NULL,
  `is_paid`       TINYINT(1) NOT NULL DEFAULT 0,
  `paid_at`       DATETIME NULL DEFAULT NULL,
  `downloaded_at` DATETIME NULL DEFAULT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`invoice_id`),
  UNIQUE KEY `uq_invoice_order` (`order_id`),
  UNIQUE KEY `uq_invoice_token` (`invoice_token`),
  KEY `idx_invoice_user` (`user_id`),
  CONSTRAINT `fk_invoice_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`order_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_invoice_user`  FOREIGN KEY (`user_id`)  REFERENCES `users`  (`user_id`)  ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------- Procedure called by COD/eSewa/Khalti handlers ----------
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
        (`order_id`, `user_id`, `delivery_mode`, `special_notes`, `kot_status`)
    VALUES
        (p_order_id, p_user_id,
         CASE WHEN p_delivery_mode IN ('delivery','takeaway') THEN p_delivery_mode ELSE 'delivery' END,
         p_special_notes,
         'active')
    ON DUPLICATE KEY UPDATE
        `delivery_mode` = VALUES(`delivery_mode`),
        `special_notes` = VALUES(`special_notes`);

    INSERT INTO `kot_invoices`
        (`order_id`, `user_id`, `invoice_token`, `is_paid`)
    SELECT p_order_id, p_user_id, v_token, 0
    FROM DUAL
    WHERE NOT EXISTS (
        SELECT 1 FROM `kot_invoices` WHERE `order_id` = p_order_id
    );
END//
DELIMITER ;

-- ---------- Seed delivery locations ----------
INSERT IGNORE INTO `delivery_locations`
  (`location_name`, `block_name`, `is_active`, `sort_order`)
VALUES
  ('IT & NOC',                               'WLV Block',      1, 10),
  ('RTE (Registry, Timetable & Examination)', 'WLV Block',      1, 20),
  ('Finance',                                'WLV Block',      1, 30),
  ('WLV SSD',                                'WLV Block',      1, 40),
  ('WLV PAT',                                'WLV Block',      1, 50),
  ('Academics A',                            'WLV Block',      1, 60),
  ('Academics B',                            'WLV Block',      1, 70),
  ('CEO Office',                             'WLV Block',      1, 80),
  ('ING SSD',                                'ING Block',      1, 110),
  ('ING PAT',                                'ING Block',      1, 120),
  ('PATIO',                                  'ING Block',      1, 130),
  ('Library',                                'Library Block',  1, 210),
  ('IMBA Lounge',                            'Library Block',  1, 220),
  ('AD (Admission Department)',              'Library Block',  1, 230),
  ('BD (Business Department)',               'HCK Block',      1, 310),
  ('HR (Human Resource)',                    'Resource Block', 1, 410),
  ('Academics C',                            'Resource Block', 1, 420),
  ('Academics D',                            'Resource Block', 1, 430),
  ('Academics E',                            'Resource Block', 1, 440),
  ('Academics F',                            'Resource Block', 1, 450),
  ('Academics G',                            'Resource Block', 1, 460),
  ('Academics H',                            'Resource Block', 1, 470),
  ('New Academics',                          'Resource Block', 1, 480);

-- ---------- Cleanup helper procedures ----------
DROP PROCEDURE IF EXISTS hc_add_column_if_missing;
DROP PROCEDURE IF EXISTS hc_add_index_if_missing;

-- Done.
