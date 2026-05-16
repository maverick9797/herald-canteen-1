-- ============================================================
-- user_logs_migration.sql
-- Run this once in phpMyAdmin (or your MySQL client) to enable
-- the Login & Session Events tab in User Logs.
--
-- This table stores login_success, login_failed, logout, and
-- access_denied events. It is written to by log_user_event()
-- in includes/functions.php which is already wired into:
--   - pages/portal-login.php   (login attempts & successes)
--   - pages/verify_role_secret.php (chef/staff role verification)
--   - pages/logout.php         (session logout)
--   - pages/user-logs.php      (access-denied guard)
-- ============================================================

CREATE TABLE IF NOT EXISTS `user_logs` (
  `log_id`      INT(10) UNSIGNED    NOT NULL AUTO_INCREMENT,
  `user_id`     INT(10) UNSIGNED    NULL     DEFAULT NULL
                COMMENT 'NULL for anonymous events (e.g. login_failed before user lookup)',
  `event_type`  VARCHAR(50)         NOT NULL
                COMMENT 'login_success | login_failed | logout | access_denied',
  `ip_address`  VARCHAR(45)         NOT NULL DEFAULT ''
                COMMENT 'IPv4 or IPv6 address of the requester',
  `description` TEXT                NOT NULL DEFAULT ''
                COMMENT 'Human-readable detail string',
  `created_at`  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`),
  KEY `idx_user_logs_user`       (`user_id`),
  KEY `idx_user_logs_event_date` (`event_type`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Optional foreign key — only add if you want cascade-deletes
-- (i.e. deleting a user also wipes their log entries).
-- Comment this out if you prefer to keep orphaned log rows.
ALTER TABLE `user_logs`
  ADD CONSTRAINT `fk_user_logs_user`
    FOREIGN KEY (`user_id`)
    REFERENCES `users` (`user_id`)
    ON DELETE SET NULL
    ON UPDATE CASCADE;
