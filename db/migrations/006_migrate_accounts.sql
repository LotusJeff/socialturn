-- Migration 006: Migrate accounts table to new schema
--
-- Old accounts stored platform credentials in data1-data4:
--   Facebook: data1=page_id, data2=page_access_token, data3=NULL, data4=NULL
--   Twitter:  data1=user_id, data2=oauth_token, data3=token_secret, data4=profile_image_url
--
-- This migration:
--   1. Renames legacy columns (companyid->company_id, active->is_active)
--   2. Converts record_created (unix int) to created_at (DATETIME)
--   3. Adds new columns: connected_platform_id, display_name, default_tags, is_posting
--   4. Migrates platform credentials into connected_platforms
--   5. Links each account to its connected_platforms row
--   6. Archives accounts that could not be migrated (type=google or missing data1)
--   7. Drops legacy columns: type, data1, data2, data3, data4, record_created
--
-- NOTE: After running this migration, verify all accounts have a
--       connected_platform_id. Accounts with NULL were archived (is_active=0).
--       Reconnect them via the UI or permanently delete them.

-- Step 1: Rename legacy columns
ALTER TABLE `accounts`
    RENAME COLUMN `companyid` TO `company_id`,
    RENAME COLUMN `active`    TO `is_active`;

-- Step 2: Change column types
ALTER TABLE `accounts`
    MODIFY COLUMN `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    MODIFY COLUMN `company_id` INT UNSIGNED NOT NULL;

-- Step 3: Add new columns (all nullable initially)
ALTER TABLE `accounts`
    ADD COLUMN `connected_platform_id` INT UNSIGNED NULL DEFAULT NULL AFTER `company_id`,
    ADD COLUMN `display_name`          VARCHAR(255) NULL DEFAULT NULL AFTER `name`,
    ADD COLUMN `default_tags`          JSON         NULL DEFAULT NULL,
    ADD COLUMN `is_posting`            TINYINT(1)   NOT NULL DEFAULT 0,
    ADD COLUMN `created_at`            DATETIME     NULL DEFAULT NULL;

-- Step 4: Convert record_created to created_at
UPDATE `accounts`
    SET `created_at` = FROM_UNIXTIME(`record_created`)
    WHERE `record_created` IS NOT NULL AND `record_created` > 0;

UPDATE `accounts`
    SET `created_at` = NOW()
    WHERE `created_at` IS NULL;

ALTER TABLE `accounts`
    MODIFY COLUMN `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;

-- Step 5: Migrate platform credentials into connected_platforms
INSERT IGNORE INTO `connected_platforms`
    (`company_id`, `platform`, `platform_account_id`,
     `access_token`, `token_secret`, `platform_avatar_url`,
     `is_active`, `created_at`)
SELECT
    `company_id`,
    `type`,
    `data1`,
    `data2`,
    `data3`,
    `data4`,
    `is_active`,
    `created_at`
FROM `accounts`
WHERE `type` IN ('twitter', 'facebook', 'instagram')
  AND `data1` IS NOT NULL
  AND `data1` <> '';

-- Step 6: Link accounts to their new connected_platforms row
UPDATE `accounts` a
JOIN `connected_platforms` cp
    ON  cp.company_id          = a.company_id
    AND cp.platform            = a.type
    AND cp.platform_account_id = a.data1
SET a.`connected_platform_id` = cp.id
WHERE a.`type` IN ('twitter', 'facebook', 'instagram')
  AND a.`data1` IS NOT NULL;

-- Step 7: Archive accounts that could not be migrated
UPDATE `accounts`
    SET `is_active` = 0
    WHERE `connected_platform_id` IS NULL;

-- Step 8: Drop legacy columns
ALTER TABLE `accounts`
    DROP COLUMN `type`,
    DROP COLUMN `data1`,
    DROP COLUMN `data2`,
    DROP COLUMN `data3`,
    DROP COLUMN `data4`,
    DROP COLUMN `record_created`;

-- Step 9: Add indexes
ALTER TABLE `accounts`
    ADD KEY `idx_accounts_company_id` (`company_id`),
    ADD KEY `idx_accounts_connected_platform_id` (`connected_platform_id`),
    ADD KEY `idx_accounts_is_active` (`is_active`),
    ADD KEY `idx_accounts_is_posting` (`is_posting`),
    ADD KEY `idx_accounts_company_active_posting` (`company_id`, `is_active`, `is_posting`);

-- Step 10: Add FK constraints
ALTER TABLE `accounts`
    ADD CONSTRAINT `fk_accounts_company`
        FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_accounts_connected_platform`
        FOREIGN KEY (`connected_platform_id`) REFERENCES `connected_platforms` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE;
