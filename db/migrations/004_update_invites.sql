-- Migration 004: Update invites table
--
-- Changes:
--   - Rename companyid -> company_id (naming convention)
--   - Change id and company_id to INT UNSIGNED
--   - Recreate composite index with updated column name
--   - Add FK to companies (was missing from migration 001)

ALTER TABLE `invites`
    RENAME COLUMN `companyid` TO `company_id`;

ALTER TABLE `invites`
    MODIFY COLUMN `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    MODIFY COLUMN `company_id` INT UNSIGNED NOT NULL;

ALTER TABLE `invites`
    DROP KEY `idx_invites_company_email`,
    ADD  KEY `idx_invites_company_email` (`company_id`, `email`);

ALTER TABLE `invites`
    ADD CONSTRAINT `fk_invites_company`
        FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE;
