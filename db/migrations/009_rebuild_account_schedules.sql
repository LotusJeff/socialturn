-- Migration 009: Rebuild account schedules
--
-- Drops the old accounts_schedule table (incompatible structure).
-- Creates new account_schedules table with:
--   - schedule_type ENUM supporting both interval and time_specific modes
--   - every_15min added to interval ENUM
--   - every_8hr added to interval ENUM
--   - active_hours window (interval mode only)
--   - timezone per account
--
-- NOTE: Existing schedule data is not migrated. Schedules must be
--       reconfigured via the UI after this migration runs.

DROP TABLE IF EXISTS `accounts_schedule`;

CREATE TABLE `account_schedules` (
    `id`                      INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `account_id`              INT UNSIGNED      NOT NULL                           COMMENT 'One schedule per account',
    `schedule_type`           ENUM('interval','time_specific') NOT NULL            COMMENT 'interval: uses interval+active_hours; time_specific: uses account_schedule_slots',
    `interval`                ENUM('every_15min','every_30min','every_hour','every_2hr','every_4hr','every_8hr','custom') NULL DEFAULT NULL COMMENT 'Posting frequency; only used when schedule_type=interval',
    `custom_interval_minutes` SMALLINT UNSIGNED NULL     DEFAULT NULL              COMMENT 'Minutes between posts; only used when interval=custom',
    `active_hours_start`      TINYINT UNSIGNED  NOT NULL DEFAULT 8                 COMMENT 'Hour (0-23) posting window opens; only used when schedule_type=interval; interpreted in timezone',
    `active_hours_end`        TINYINT UNSIGNED  NOT NULL DEFAULT 20                COMMENT 'Hour (0-23) posting window closes; only used when schedule_type=interval; interpreted in timezone',
    `timezone`                VARCHAR(64)       NOT NULL DEFAULT 'UTC'             COMMENT 'PHP timezone string (e.g. America/New_York); applies to all schedule calculations',
    `created_at`              DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`              DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_account_schedules_account_id` (`account_id`),
    CONSTRAINT `fk_account_schedules_account`
        FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
