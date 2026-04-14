-- Migration 003: Update users table
--
-- Changes:
--   - Rename companyid -> company_id
--   - Change id and company_id to INT UNSIGNED
--   - Convert record_created (unix int) to created_at (DATETIME)
--   - Add UNIQUE constraint on email
--   - Add FK constraint to companies
--   - Add missing indexes

ALTER TABLE `users`
    RENAME COLUMN `companyid` TO `company_id`;

ALTER TABLE `users`
    MODIFY COLUMN `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    MODIFY COLUMN `company_id` INT UNSIGNED NOT NULL;

-- Add created_at, populate from record_created, then drop record_created
ALTER TABLE `users`
    ADD COLUMN `created_at` DATETIME NULL DEFAULT NULL;

UPDATE `users`
    SET `created_at` = FROM_UNIXTIME(`record_created`)
    WHERE `record_created` IS NOT NULL AND `record_created` > 0;

UPDATE `users`
    SET `created_at` = NOW()
    WHERE `created_at` IS NULL;

ALTER TABLE `users`
    MODIFY COLUMN `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    DROP COLUMN `record_created`;

ALTER TABLE `users`
    ADD UNIQUE KEY `uq_users_email` (`email`),
    ADD KEY `idx_users_company_id` (`company_id`),
    ADD KEY `idx_users_active` (`active`);

ALTER TABLE `users`
    ADD CONSTRAINT `fk_users_company`
        FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE;
