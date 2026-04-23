-- Migration 031 — Add post_images table; drop image columns from posts; add overlay settings to accounts
--
-- post_images: replaces posts.image_filename and posts.image_source with a
--   dedicated table supporting multiple images per post. sort_order controls
--   display/posting sequence. image_source mirrors the ENUM from the dropped columns.
--
-- posts: image_filename and image_source dropped — image data now lives in post_images.
--
-- accounts: overlay_font_color and overlay_font_size added for per-account
--   control of dynamic image text rendering in ImageService::generateFromTemplate().

-- ------------------------------------------------------------
-- post_images
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `post_images` (
    `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `post_id`        INT UNSIGNED  NOT NULL                           COMMENT 'Owning post',
    `sort_order`     TINYINT UNSIGNED NOT NULL DEFAULT 0              COMMENT 'Display/posting sequence; 0 = first',
    `image_filename` VARCHAR(255)  NOT NULL                           COMMENT 'Storage-relative path or bare filename depending on image_source',
    `image_source`   ENUM('uploaded','generated','url_fetched') NOT NULL COMMENT 'Origin: uploaded=content form, generated=ImageService template, url_fetched=reserved',
    `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_post_images_post_sort` (`post_id`, `sort_order`),
    CONSTRAINT `fk_post_images_post`
        FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- posts — drop image columns (moved to post_images)
-- ------------------------------------------------------------
ALTER TABLE `posts`
    DROP COLUMN `image_filename`,
    DROP COLUMN `image_source`;

-- ------------------------------------------------------------
-- accounts — overlay display settings for ImageService
-- ------------------------------------------------------------
ALTER TABLE `accounts`
    ADD COLUMN `overlay_font_color` VARCHAR(7)       NULL DEFAULT NULL COMMENT 'Hex color for image overlay text, e.g. #ffffff; NULL = ImageService default'
        AFTER `base_image_filename`,
    ADD COLUMN `overlay_font_size`  TINYINT UNSIGNED NULL DEFAULT NULL COMMENT 'Font size in points for image overlay text; NULL = ImageService default'
        AFTER `overlay_font_color`;
