-- Migration 021 — Create activity_log table
--
-- Operational event log for cron runs, post outcomes, token events,
-- and queue population. Rows older than 48 hours are purged automatically
-- at the start of each cron run — this table is a rolling window, not
-- a permanent audit trail (post_history is the permanent audit trail).
--
-- Upgrade: run this script on existing installations.
-- Fresh installs: activity_log is included in schema.sql.

CREATE TABLE `activity_log` (
    `id`                    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `company_id`            INT UNSIGNED  NOT NULL                           COMMENT 'Owning company',
    `account_id`            INT UNSIGNED  NULL     DEFAULT NULL              COMMENT 'Account context; NULL for company-level events (e.g. cron_run)',
    `connected_platform_id` INT UNSIGNED  NULL     DEFAULT NULL              COMMENT 'Platform connection context; NULL when not applicable',
    `event_type`            ENUM(
                                'cron_run',
                                'post_success',
                                'post_failure',
                                'token_refresh',
                                'token_verify',
                                'queue_populate',
                                'connection_test'
                            ) NOT NULL                                       COMMENT 'Structured event type for filtering',
    `message`               TEXT          NOT NULL                           COMMENT 'Human-readable event description',
    `context`               JSON          NULL     DEFAULT NULL              COMMENT 'Structured event data (counts, IDs, errors); never include tokens',
    `created_at`            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_activity_log_company_time`   (`company_id`,            `created_at`),
    INDEX `idx_activity_log_account_time`   (`account_id`,            `created_at`),
    INDEX `idx_activity_log_platform_time`  (`connected_platform_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
