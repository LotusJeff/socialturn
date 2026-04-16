-- Migration 022 — Add last_login column to users
--
-- Records the datetime of each successful login.
-- Displayed in the team dashboard (Section 5 — Team Management).
-- NULL means the user has never logged in via the new login flow.
--
-- Upgrade: run this script on existing installations.
-- Fresh installs: last_login is included in schema.sql.

ALTER TABLE `users`
    ADD COLUMN `last_login` DATETIME NULL DEFAULT NULL
        AFTER `active`
        COMMENT 'Set on every successful login — displayed in team dashboard';
