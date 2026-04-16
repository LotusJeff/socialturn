-- Migration 023 — Add body_normalized to posts
-- Adds a normalized body fingerprint column used for duplicate detection.
-- No backfill: existing rows default to ''. They will be normalized lazily
-- the first time each post passes through a write path (store, update, import).
-- The duplicate detection view excludes rows where body_normalized = ''.

ALTER TABLE posts
    ADD COLUMN body_normalized VARCHAR(280) NOT NULL DEFAULT '' AFTER body;

CREATE INDEX idx_posts_body_normalized
    ON posts (account_id, body_normalized);
