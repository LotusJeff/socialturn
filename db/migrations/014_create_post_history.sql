-- Migration 014: Create post_history table
--
-- Immutable audit log of every post attempt (success or failure).
-- Written by cron after each send attempt. Never updated after insert.
--
-- Key columns are denormalized (platform, platform_account_id, body_snapshot)
-- so the log remains meaningful even if connected_platforms or posts are
-- later deleted.
--
-- error_message must be sanitized before storage -- never store raw tokens.

CREATE TABLE `post_history` (
    `id`                    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `connected_platform_id` INT UNSIGNED  NOT NULL                           COMMENT 'FK preserved for dashboard queries while connection exists',
    `post_id`               INT UNSIGNED  NOT NULL                           COMMENT 'FK preserved while post exists',
    `scheduled_post_id`     INT UNSIGNED  NOT NULL                           COMMENT 'One history row per queue entry',
    `platform`              ENUM('twitter','facebook','instagram') NOT NULL  COMMENT 'Denormalized -- survives connected_platform deletion',
    `platform_account_id`   VARCHAR(100)  NOT NULL                           COMMENT 'Denormalized page/profile ID at time of posting',
    `body_snapshot`         TEXT          NOT NULL                           COMMENT 'Exact text as sent; unaffected by future edits to the source post',
    `image_filename`        VARCHAR(255)  NULL     DEFAULT NULL              COMMENT 'Image filename at time of posting',
    `platform_post_id`      VARCHAR(100)  NULL     DEFAULT NULL              COMMENT 'ID returned by platform API (tweet ID, FB post ID, IG media ID); NULL on failure',
    `status`                ENUM('posted','failed') NOT NULL                 COMMENT 'Outcome of the post attempt',
    `error_message`         TEXT          NULL     DEFAULT NULL              COMMENT 'Platform error detail when status=failed; NULL on success; sanitized before storage',
    `posted_at`             DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When cron ran this post attempt',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_post_history_scheduled_post_id` (`scheduled_post_id`),
    KEY `idx_post_history_connected_platform_id` (`connected_platform_id`),
    KEY `idx_post_history_post_id` (`post_id`),
    KEY `idx_post_history_status` (`status`),
    KEY `idx_post_history_posted_at` (`posted_at`),
    CONSTRAINT `fk_post_history_connected_platform`
        FOREIGN KEY (`connected_platform_id`) REFERENCES `connected_platforms` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_post_history_post`
        FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_post_history_scheduled_post`
        FOREIGN KEY (`scheduled_post_id`) REFERENCES `scheduled_posts` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
