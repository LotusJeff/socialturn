-- ============================================================
-- Migration 016 -- Add final_body to scheduled_posts
-- ============================================================
--
-- Adds final_body TEXT NOT NULL to scheduled_posts.
-- Stores the post body with hashtags already appended by
-- TagAppenderService at queue population time so cron sends
-- the pre-rendered string without recomputing tags.
--
-- Run against an existing database to upgrade.
-- Fresh installs should use the updated schema.sql instead.
--
-- ============================================================

ALTER TABLE `scheduled_posts`
    ADD COLUMN `final_body` TEXT NOT NULL
        COMMENT 'Post body with hashtags appended by TagAppenderService at queue population time; sent verbatim by cron'
        AFTER `post_id`;
