-- Migration 005: Create connected_platforms table
--
-- Company-level OAuth token store. One row per real-world platform
-- page/profile. Accounts will reference these rows via
-- accounts.connected_platform_id (added in migration 006).
--
-- Unique constraint (company_id, platform, platform_account_id) prevents
-- the same real-world page from being connected twice under one company.

CREATE TABLE `connected_platforms` (
    `id`                  INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `company_id`          INT UNSIGNED   NOT NULL                           COMMENT 'Owning company',
    `platform`            ENUM('twitter','facebook','instagram') NOT NULL   COMMENT 'Platform identifier',
    `platform_account_id` VARCHAR(100)   NOT NULL                           COMMENT 'Platform own ID: FB Page ID, Twitter user ID, IG account ID',
    `platform_name`       VARCHAR(255)   NULL     DEFAULT NULL              COMMENT 'Display name as it appears on the platform',
    `platform_username`   VARCHAR(255)   NULL     DEFAULT NULL              COMMENT 'Handle or slug -- display only',
    `platform_avatar_url` VARCHAR(512)   NULL     DEFAULT NULL              COMMENT 'Profile image URL cached for UI; may become stale',
    `access_token`        TEXT           NOT NULL                           COMMENT 'OAuth access token -- never log, never expose in views or responses',
    `token_secret`        VARCHAR(512)   NULL     DEFAULT NULL              COMMENT 'OAuth 1.0a token secret (Twitter only); NULL for OAuth 2.0 platforms',
    `token_expires_at`    DATETIME       NULL     DEFAULT NULL              COMMENT 'NULL=never expires (Twitter); set for Facebook/Instagram 60-day tokens',
    `is_active`           TINYINT(1)     NOT NULL DEFAULT 1                 COMMENT '0=disconnected or expired, 1=active',
    `created_at`          DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last token refresh',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_connected_platforms_company_platform_account` (`company_id`, `platform`, `platform_account_id`),
    KEY `idx_connected_platforms_company_id` (`company_id`),
    KEY `idx_connected_platforms_is_active` (`is_active`),
    KEY `idx_connected_platforms_token_expires_at` (`token_expires_at`),
    CONSTRAINT `fk_connected_platforms_company`
        FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
