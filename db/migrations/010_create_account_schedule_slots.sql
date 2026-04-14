-- Migration 010: Create account_schedule_slots table
--
-- Stores exact time-of-day posting slots for time_specific scheduling.
-- Only read by the queue engine when account_schedules.schedule_type = time_specific.
-- Slots fire every day; no day-of-week control.
-- Times are interpreted using account_schedules.timezone for the account.
-- Individual slots can be deactivated (is_active=0) without being deleted.

CREATE TABLE `account_schedule_slots` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `account_id`  INT UNSIGNED  NOT NULL                           COMMENT 'Owning account',
    `time_of_day` TIME          NOT NULL                           COMMENT 'Exact post time e.g. 07:15:00; interpreted in account_schedules.timezone',
    `is_active`   TINYINT(1)    NOT NULL DEFAULT 1                 COMMENT '0=skipped by queue engine, 1=active',
    `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_account_schedule_slots_account_time` (`account_id`, `time_of_day`),
    KEY `idx_account_schedule_slots_account_id` (`account_id`),
    CONSTRAINT `fk_account_schedule_slots_account`
        FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
