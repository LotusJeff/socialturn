-- Migration 025: Add scheduling_enabled to account_settings
--
-- Master toggle for the queue population engine per account.
-- When 0 (default), RecycleService skips queue population for this account.
-- Already-scheduled posts in scheduled_posts are dispatched regardless —
-- this toggle gates population only, not dispatch.
-- Default 0 — existing accounts must explicitly enable automated scheduling.

ALTER TABLE `account_settings`
    ADD COLUMN `scheduling_enabled` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Master toggle — queue population engine runs for this account only when 1'
    AFTER `recycle_lookahead_days`;
