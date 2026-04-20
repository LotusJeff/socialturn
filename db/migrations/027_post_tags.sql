-- Migration 027: add post_tags column to posts table
-- Post-specific hashtags appended to final_body after attribution and before account default tags.
ALTER TABLE `posts`
    ADD COLUMN `post_tags` VARCHAR(255) NULL DEFAULT NULL
        COMMENT 'Post-specific hashtags appended after attribution and before account default tags'
    AFTER `attributed_to`;
