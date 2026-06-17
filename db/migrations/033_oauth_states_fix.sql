-- Migration 033: Fix oauth_states table for active use
--
-- Removes account_id (no accounts row exists during the connect flow —
-- this FK was never satisfiable and blocked any INSERT).
-- Adds app_key/app_secret columns (nullable; populated by GAP 1 later).

ALTER TABLE oauth_states
    DROP FOREIGN KEY fk_oauth_states_account,
    DROP KEY idx_oauth_states_account_id,
    DROP COLUMN account_id;

ALTER TABLE oauth_states
    ADD COLUMN app_key    VARCHAR(255) NULL DEFAULT NULL COMMENT 'Per-connection app key (populated by GAP 1)' AFTER request_token_secret,
    ADD COLUMN app_secret VARCHAR(255) NULL DEFAULT NULL COMMENT 'Per-connection app secret (populated by GAP 1)' AFTER app_key;
