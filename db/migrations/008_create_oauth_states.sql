-- Migration 008: Create oauth_states table
--
-- Replaces SESSION-based OAuth handshake state storage in connect.php.
-- One row per in-progress platform connection attempt.
-- Cron should purge rows older than 15 minutes.
--
-- request_token / request_token_secret are Twitter (OAuth 1.0a) only.
-- Facebook/Instagram OAuth 2.0 uses only state_key.

CREATE TABLE `oauth_states` (
    `id`                   INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `state_key`            CHAR(64)      NOT NULL                           COMMENT 'Random key for this OAuth session; passed as state parameter',
    `platform`             ENUM('twitter','facebook','instagram') NOT NULL  COMMENT 'Platform being connected',
    `account_id`           INT UNSIGNED  NOT NULL                           COMMENT 'Account being connected to',
    `user_id`              INT UNSIGNED  NOT NULL                           COMMENT 'User who initiated the connection',
    `request_token`        VARCHAR(512)  NULL     DEFAULT NULL              COMMENT 'OAuth 1.0a request token (Twitter only)',
    `request_token_secret` VARCHAR(512)  NULL     DEFAULT NULL              COMMENT 'OAuth 1.0a request token secret (Twitter only)',
    `created_at`           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Used to identify and purge rows older than 15 minutes',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_oauth_states_state_key` (`state_key`),
    KEY `idx_oauth_states_account_id` (`account_id`),
    KEY `idx_oauth_states_created_at` (`created_at`),
    CONSTRAINT `fk_oauth_states_account`
        FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_oauth_states_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
