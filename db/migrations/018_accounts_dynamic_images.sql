-- Migration 018 — Add dynamic image generation columns to accounts
--
-- dynamic_images_enabled: when 1, ImageService::generateFromTemplate() is called
--   at queue population time for posts that have no image_filename.
-- base_image_filename:    bare filename within images/originals/ used as the
--   template image for generateFromTemplate(). NULL = no template configured.
--
-- Run after 017_drop_suggestions.sql.

ALTER TABLE `accounts`
    ADD COLUMN `dynamic_images_enabled` TINYINT(1)   NOT NULL DEFAULT 0    COMMENT '1 = generate image from base_image_filename when post has no image; 0 = text-only posts allowed'
        AFTER `is_active`,
    ADD COLUMN `base_image_filename`    VARCHAR(255)  NULL     DEFAULT NULL COMMENT 'Base image in originals/ used by ImageService::generateFromTemplate(); NULL = no template configured'
        AFTER `dynamic_images_enabled`;
