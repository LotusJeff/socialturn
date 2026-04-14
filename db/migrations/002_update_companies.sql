-- Migration 002: Update companies table
--
-- Changes:
--   - Change id to INT UNSIGNED (required before other tables add FKs to it)
--   - Add created_at DATETIME
--   - Drop plan column (no billing model in this version)

ALTER TABLE `companies`
    MODIFY COLUMN `id` INT UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `companies`
    ADD COLUMN `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;

ALTER TABLE `companies`
    DROP COLUMN `plan`;
