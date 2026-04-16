-- Migration 019 — Add final_image_filename to scheduled_posts
--
-- Stores the processed or generated image path at queue population time.
-- ImageService runs at population (not at post time), so the image is
-- ready when cron dispatches the post. NULL = text-only post.
--
-- Value is a storage-relative path set by ImageService, e.g.:
--   'processed/twitter/twitter_photo_abc123.jpg'
--   'generated/instagram/instagram_gen_abc123.jpg'
--
-- Run after 018_accounts_dynamic_images.sql.

ALTER TABLE `scheduled_posts`
    ADD COLUMN `final_image_filename` VARCHAR(255) NULL DEFAULT NULL
        COMMENT 'Processed or generated image filename ready for posting; NULL = text-only post'
        AFTER `final_body`;
