-- Migration 011: Create account_settings table
--
-- Per-account queue engine configuration. One row per account.
-- Seeded from config.php defaults (RECYCLE_THRESHOLD_DEFAULT,
-- RECYCLE_LOOKAHEAD_DAYS) when an account is created.
--
-- recycle_threshold:      When pending queue depth falls below this
--                         number, the queue population engine runs.
-- recycle_lookahead_days: How many days ahead to schedule posts.

CREATE TABLE `account_settings` (
    `id`                     INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `account_id`             INT UNSIGNED      NOT NULL                           COMMENT 'One settings row per account',
    `recycle_threshold`      SMALLINT UNSIGNED NOT NULL DEFAULT 10                COMMENT 'Pending queue depth below which the recycler runs',
    `recycle_lookahead_days` SMALLINT UNSIGNED NOT NULL DEFAULT 30                COMMENT 'How many days ahead the queue engine schedules posts',
    `created_at`             DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`             DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_account_settings_account_id` (`account_id`),
    CONSTRAINT `fk_account_settings_account`
        FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
