-- Migration 034: Per-connection developer app credentials
--
-- Moves Twitter/Meta app credentials from global admin_settings constants
-- to per-row storage on connected_platforms. Each platform connection now
-- carries its own app_key and app_secret, enabling multiple independent
-- developer apps to coexist (e.g. two Twitter accounts under separate apps).
--
-- Phase A: Add columns (nullable — existing rows are NULL until backfilled).
-- Phase B: Backfill from admin_settings so existing connections continue to work.
-- Phase C: Remove the now-obsolete admin_settings rows.
--
-- Run AFTER deploying the code changes. Do not run this migration before
-- the new code is live — the code must handle nullable app_key/app_secret
-- gracefully, and the connect flow must not write to admin_settings.

-- Phase A: Add columns to connected_platforms
ALTER TABLE connected_platforms
    ADD COLUMN app_key    VARCHAR(255) NULL DEFAULT NULL COMMENT 'Per-connection developer app key (Twitter Consumer Key; Meta App ID)'   AFTER token_expires_at,
    ADD COLUMN app_secret VARCHAR(255) NULL DEFAULT NULL COMMENT 'Per-connection developer app secret (Twitter Consumer Secret; Meta App Secret)' AFTER app_key;

-- Phase B: Backfill existing connections from admin_settings
UPDATE connected_platforms
    SET app_key    = (SELECT setting_val FROM admin_settings WHERE setting_key = 'twitter_apikey'),
        app_secret = (SELECT setting_val FROM admin_settings WHERE setting_key = 'twitter_apisecret')
    WHERE platform = 'twitter';

UPDATE connected_platforms
    SET app_key    = (SELECT setting_val FROM admin_settings WHERE setting_key = 'meta_app_id'),
        app_secret = (SELECT setting_val FROM admin_settings WHERE setting_key = 'meta_app_secret')
    WHERE platform IN ('facebook', 'instagram');

-- Phase C: Remove the global credential rows from admin_settings
DELETE FROM admin_settings
    WHERE setting_key IN ('twitter_apikey', 'twitter_apisecret', 'meta_app_id', 'meta_app_secret');
