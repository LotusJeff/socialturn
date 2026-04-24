-- Migration 032: Multi-image support
-- Replaces single final_image_filename (VARCHAR 255) with final_image_filenames (TEXT)
-- in both scheduled_posts and post_history. Stores a JSON array of processed image
-- filenames. NULL means text-only post. Applied in two steps: drop old column, add new.

ALTER TABLE `scheduled_posts`
    DROP COLUMN `final_image_filename`,
    ADD COLUMN  `final_image_filenames` TEXT NULL DEFAULT NULL
        COMMENT 'JSON array of processed image filenames ready for posting; NULL = text-only post'
        AFTER `final_body`;

ALTER TABLE `post_history`
    DROP COLUMN `image_filename`,
    ADD COLUMN  `image_filenames` TEXT NULL DEFAULT NULL
        COMMENT 'JSON array of image filenames at time of posting; NULL = text-only post'
        AFTER `body_snapshot`;
