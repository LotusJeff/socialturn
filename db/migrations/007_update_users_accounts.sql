-- Migration 007: Update users_accounts table
--
-- Changes:
--   - Rename companyid->company_id, userid->user_id, accountid->account_id
--   - Change all columns to INT UNSIGNED (now that companies, users,
--     and accounts have been updated to UNSIGNED in migrations 002-006)
--   - Add UNIQUE constraint on (user_id, account_id)
--   - Add FK constraints to companies, users, accounts

ALTER TABLE `users_accounts`
    RENAME COLUMN `companyid` TO `company_id`,
    RENAME COLUMN `userid`    TO `user_id`,
    RENAME COLUMN `accountid` TO `account_id`;

ALTER TABLE `users_accounts`
    MODIFY COLUMN `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    MODIFY COLUMN `company_id` INT UNSIGNED NOT NULL,
    MODIFY COLUMN `user_id`    INT UNSIGNED NOT NULL,
    MODIFY COLUMN `account_id` INT UNSIGNED NOT NULL;

ALTER TABLE `users_accounts`
    ADD UNIQUE KEY `uq_users_accounts_user_account` (`user_id`, `account_id`),
    ADD KEY `idx_users_accounts_company_id` (`company_id`),
    ADD KEY `idx_users_accounts_user_id` (`user_id`),
    ADD KEY `idx_users_accounts_account_id` (`account_id`);

ALTER TABLE `users_accounts`
    ADD CONSTRAINT `fk_users_accounts_company`
        FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_users_accounts_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_users_accounts_account`
        FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE;
