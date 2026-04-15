-- Migration 020 — Add attributed_to column to posts
--
-- Adds optional attribution/author field to the content library.
-- When populated, attribution is appended as "-- Author" after the
-- post body at send time and affects image overlay layout:
--   Layout A (NULL): post text centered vertically in overlay bar.
--   Layout B (set):  post text in upper portion; attribution
--                    right-aligned in lower portion at smaller size.
-- Tags are NEVER placed on images regardless of attribution.
--
-- Upgrade: run this script on existing installations.
-- Fresh installs: attributed_to is included in schema.sql.

ALTER TABLE `posts`
    ADD COLUMN `attributed_to` VARCHAR(255) NULL
        AFTER `body`
        COMMENT 'Attribution/author — appended as "-- Author" after post body; affects image overlay layout in ImageService. Never included in image text — post body and attribution are overlaid on image, tags are text-only.';
