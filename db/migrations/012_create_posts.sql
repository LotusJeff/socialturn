-- Migration 012: Create posts table
--
-- The content library. Master record for all postable content.
-- Replaces the inline message/image columns on the old accounts_queue.
-- The queue engine draws from this table to populate scheduled_posts.
--
-- is_recyclable=1: post re-enters the queue after being sent.
-- is_recyclable=0: post is sent once, then is_active is set to 0 by cron.
-- is_active=0:     post is excluded from all queue population.
-- internal_note:   operator-facing only; never sent or shown publicly.

CREATE TABLE `posts` (
    `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `account_id`     INT UNSIGNED  NOT NULL                           COMMENT 'Which account this content belongs to',
    `body`           TEXT          NOT NULL                           COMMENT 'The post text',
    `image_filename` VARCHAR(255)  NULL     DEFAULT NULL              COMMENT 'Filename within images/; NULL=text-only post',
    `is_recyclable`  TINYINT(1)    NOT NULL DEFAULT 1                 COMMENT '1=re-enters queue after posting; 0=sent once then deactivated',
    `is_active`      TINYINT(1)    NOT NULL DEFAULT 1                 COMMENT '1=eligible for queue population; 0=excluded from all queues',
    `internal_note`  TEXT          NULL     DEFAULT NULL              COMMENT 'Operator note -- never sent, never shown publicly, visible in UI only',
    `created_by`     INT UNSIGNED  NOT NULL                           COMMENT 'User who created this post',
    `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_posts_account_id` (`account_id`),
    KEY `idx_posts_queue_filter` (`account_id`, `is_active`, `is_recyclable`),
    CONSTRAINT `fk_posts_account`
        FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_posts_created_by`
        FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
