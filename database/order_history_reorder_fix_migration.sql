-- Herald Canteen reorder + customer order history database safety migration
-- Safe to run multiple times. Does not delete orders, cart data, or payment records.

CREATE DATABASE IF NOT EXISTS `herald_canteen` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `herald_canteen`;

DROP PROCEDURE IF EXISTS `hc_add_index_if_missing`;
DROP PROCEDURE IF EXISTS `hc_add_unique_index_if_missing`;

DELIMITER //
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

CREATE PROCEDURE `hc_add_unique_index_if_missing`(
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

-- Active order tracking and 7-day order history lookup.
SET @hc_sql = 'CREATE INDEX `idx_orders_user_status_updated` ON `orders` (`user_id`, `status`, `updated_at`)';
CALL hc_add_index_if_missing('orders', 'idx_orders_user_status_updated', @hc_sql);

-- Order item loading for history/reorder pages.
SET @hc_sql = 'CREATE INDEX `idx_order_items_order` ON `order_items` (`order_id`)';
CALL hc_add_index_if_missing('order_items', 'idx_order_items_order', @hc_sql);

-- Cart should have one row per user/item. This is required for safe idempotent reorder.
SET @hc_sql = 'CREATE UNIQUE INDEX `uq_cart_user_item` ON `cart` (`user_id`, `item_id`)';
CALL hc_add_unique_index_if_missing('cart', 'uq_cart_user_item', @hc_sql);

-- Cart lookup by user remains fast even when the unique key already exists.
SET @hc_sql = 'CREATE INDEX `idx_cart_user` ON `cart` (`user_id`)';
CALL hc_add_index_if_missing('cart', 'idx_cart_user', @hc_sql);

-- Repair any stale cart total_price values using current menu prices.
UPDATE `cart` c
JOIN `menu_items` m ON m.`item_id` = c.`item_id`
SET c.`total_price` = ROUND(c.`quantity` * m.`price`, 2)
WHERE c.`total_price` <> ROUND(c.`quantity` * m.`price`, 2);

DROP PROCEDURE IF EXISTS `hc_add_index_if_missing`;
DROP PROCEDURE IF EXISTS `hc_add_unique_index_if_missing`;
