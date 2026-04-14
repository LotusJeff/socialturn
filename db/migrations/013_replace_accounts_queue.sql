-- Migration 013: Replace accounts_queue with scheduled_posts
--
-- The old accounts_queue stored post content inline (message, image).
-- The new scheduled_posts references posts by ID and uses a proper
-- status ENUM with idempotency support (locked_at).
--
-- NOTE: Existing queue data is NOT migrated. The data model is
--       incompatible (inline content vs referenced posts table).
--       The queue will be repopulated automatically by cron once
--       posts are added to the content library and accounts are
--       configured with is_posting=1.

DROP TABLE IF EXISTS `accounts_queue`;

CREATE TABLE `scheduled_posts` (
    `id`                    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `connected_platform_id` INT UNSIGNED  NOT NULL                           COMMENT 'Which platform connection to post through',
    `post_id`               INT UNSIGNED  NOT NULL                           COMMENT 'Which content to post',
    `scheduled_time`        DATETIME      NOT NULL                           COMMENT 'When this post should be sent',
    `status`                ENUM('pending','posted','failed','skipped') NOT NULL DEFAULT 'pending' COMMENT 'Cron only processes pending rows',
    `locked_at`             DATETIME      NULL     DEFAULT NULL              COMMENT 'Set atomically by cron when claiming a row; prevents double-posting on overlapping runs; cleared on failure',
    `created_at`            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_scheduled_posts_platform_status_time` (`connected_platform_id`, `status`, `scheduled_time`),
    KEY `idx_scheduled_posts_post_platform_status` (`post_id`, `connected_platform_id`, `status`),
    CONSTRAINT `fk_scheduled_posts_connected_platform`
        FOREIGN KEY (`connected_platform_id`) REFERENCES `connected_platforms` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_scheduled_posts_post`
        FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
