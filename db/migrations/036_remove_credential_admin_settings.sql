-- ============================================================
-- Migration 036: Remove deprecated global credential rows
-- ============================================================
--
-- Deletes the four admin_settings rows that stored platform
-- developer app credentials globally before v0.9.5 (GAP 1).
-- Credentials are now stored per-connection on connected_platforms.
--
-- These rows were seeded by migration 026. Schema.sql was updated
-- in v0.9.5 to omit them, but migration 026 continued to insert
-- them on fresh installs. This migration removes them for any
-- install where they are still present.
--
-- Safe to run when the rows are already absent —
-- DELETE ... WHERE is a no-op if no matching rows exist.
--
-- Fresh installs: already excluded from schema.sql — do not
-- run this file separately on a new install.
--
-- Upgrades from 0.9.x: run this migration once.
-- ============================================================

DELETE FROM `admin_settings`
    WHERE `setting_key` IN (
        'twitter_apikey',
        'twitter_apisecret',
        'meta_app_id',
        'meta_app_secret'
    );
