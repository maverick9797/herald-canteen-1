-- ============================================================
-- Herald Canteen — OTP / MFA Migration
-- Run this ONCE against your existing herald_canteen database.
-- Safe to run on fresh installs too. MySQL-safe; no ADD COLUMN IF NOT EXISTS syntax is used.
-- ============================================================

-- 1. Add MFA columns to users table
-- MySQL-safe conditional DDL: avoids unsupported ADD COLUMN IF NOT EXISTS.
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

SET @hc_sql = 'ALTER TABLE `users` ADD COLUMN `mfa_enabled` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = Login OTP required, 0 = password-only login'' AFTER `is_active`';
CALL hc_add_column_if_missing('users', 'mfa_enabled', @hc_sql);

SET @hc_sql = 'ALTER TABLE `users` ADD COLUMN `email_verified_at` DATETIME NULL DEFAULT NULL COMMENT ''Timestamp of first successful email OTP verification'' AFTER `mfa_enabled`';
CALL hc_add_column_if_missing('users', 'email_verified_at', @hc_sql);

DROP PROCEDURE IF EXISTS hc_add_column_if_missing;

-- 2. Create the otp_tokens table
--    One row per active OTP.  Old/expired rows are invalidated on re-issue
--    and cleaned up by a scheduled event (or on-demand purge in otp_helpers.php).
CREATE TABLE IF NOT EXISTS `otp_tokens` (
    `otp_id`        INT(10) UNSIGNED    NOT NULL AUTO_INCREMENT,

    -- Who the OTP belongs to.  NULL is allowed so we can issue forgot-password
    -- OTPs before we have confirmed the user exists (avoids email enumeration).
    `user_id`       INT(10) UNSIGNED    NULL     DEFAULT NULL,

    -- The email address this OTP was sent to.
    -- For login/email-change we store the target email here.
    `email`         VARCHAR(150)        NOT NULL,

    -- Purpose prevents cross-use attacks (e.g. a login OTP used as a
    -- forgot-password token).
    `purpose`       ENUM(
                        'register',
                        'login',
                        'forgot_password',
                        'email_change',
                        'enable_mfa'
                    )                   NOT NULL,

    -- The hashed OTP (password_hash — never stored plain).
    `otp_hash`      VARCHAR(255)        NOT NULL,

    -- Hard expiry stored in the DB so we can check it in SQL if needed.
    `expires_at`    DATETIME            NOT NULL,

    -- Attempt counter — invalidate after 5 wrong guesses.
    `attempts`      TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,

    -- Marks the token as used / invalidated without deleting the row
    -- (lets us show "already used" vs "expired" messages and prevents
    --  replay attacks if a second request hits before the DELETE runs).
    `is_used`       TINYINT(1)          NOT NULL DEFAULT 0,

    -- For email_change purpose: the new email the user wants to change to.
    -- NULL for login / forgot_password.
    `new_email`     VARCHAR(150)        NULL     DEFAULT NULL,

    `created_at`    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`otp_id`),
    KEY `idx_otp_email_purpose` (`email`, `purpose`),
    KEY `idx_otp_user_purpose`  (`user_id`, `purpose`),
    KEY `idx_otp_expires`       (`expires_at`),

    CONSTRAINT `fk_otp_user`
        FOREIGN KEY (`user_id`)
        REFERENCES  `users` (`user_id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_general_ci
  COMMENT='Hashed one-time passwords for login MFA, forgot-password, and email-change flows';

-- 3. Mark existing users as email-verified (they were already in the system
--    before OTP was introduced — we trust their emails).
UPDATE `users`
SET    `email_verified_at` = `created_at`
WHERE  `email_verified_at` IS NULL;

UPDATE `users`
SET    `mfa_enabled` = 0;

-- 4. Optional scheduled event: auto-purge expired OTP rows every hour.
--    Requires the MySQL event scheduler to be ON:
--      SET GLOBAL event_scheduler = ON;
--    If you prefer to skip this, the PHP helper also purges on each OTP issue.
-- DROP EVENT IF EXISTS `evt_purge_expired_otps`;
-- CREATE EVENT `evt_purge_expired_otps`
--     ON SCHEDULE EVERY 1 HOUR
--     DO DELETE FROM `otp_tokens` WHERE `expires_at` < NOW() OR `is_used` = 1;


ALTER TABLE `users`
    MODIFY COLUMN `mfa_enabled` TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE `otp_tokens`
    MODIFY COLUMN `purpose` ENUM('register','login','forgot_password','email_change','enable_mfa') NOT NULL;


CREATE TABLE IF NOT EXISTS `pending_registrations` (
    `pending_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `full_name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(150) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `phone` VARCHAR(20) NULL DEFAULT NULL,
    `role` ENUM('customer') NOT NULL DEFAULT 'customer',
    `expires_at` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_pending_registration_email` (`email`),
    KEY `idx_pending_registration_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DELETE FROM `pending_registrations`
WHERE `expires_at` < NOW();

-- Optional cleanup for abandoned unverified customer rows created by older versions:
-- DELETE FROM `users`
-- WHERE `role` = 'customer'
-- AND `email_verified_at` IS NULL
-- AND `created_at` < (NOW() - INTERVAL 1 DAY);


-- Operational history hide/clear table (non-destructive)
CREATE TABLE IF NOT EXISTS `order_history_hidden` (
    `hidden_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_id` INT(10) UNSIGNED NOT NULL,
    `hidden_for_role` ENUM('chef','staff') NOT NULL,
    `hidden_by` INT(10) UNSIGNED NULL DEFAULT NULL,
    `hidden_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`hidden_id`),
    UNIQUE KEY `uq_hidden_role_order` (`hidden_for_role`, `order_id`),
    KEY `idx_hidden_order` (`order_id`),
    KEY `idx_hidden_role` (`hidden_for_role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
