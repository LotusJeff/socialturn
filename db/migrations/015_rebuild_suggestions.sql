-- Migration 015: Rebuild suggestions table
--
-- The old suggestions table used the Twitter tweet ID as a VARCHAR primary key.
-- The new schema uses an auto-increment INT PK with source_id for deduplication.
-- Column renames: text->body, media->media_url, list->list_name (avoids reserved word).
-- source column added to support future non-Twitter suggestion sources.
--
-- NOTE: Existing suggestion data is discarded. This is safe -- the cron job
--       deletes and re-fetches all suggestions on every run anyway.

DROP TABLE IF EXISTS `suggestions`;

CREATE TABLE `suggestions` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `source`      VARCHAR(50)   NOT NULL DEFAULT 'twitter'         COMMENT 'Origin platform',
    `source_id`   VARCHAR(100)  NOT NULL                           COMMENT 'External ID (e.g. tweet ID); prevents re-inserting on refresh',
    `body`        TEXT          NOT NULL                           COMMENT 'Suggestion text',
    `screen_name` VARCHAR(100)  NULL     DEFAULT NULL              COMMENT 'Author handle on the source platform',
    `media_url`   VARCHAR(512)  NULL     DEFAULT NULL              COMMENT 'Attached media URL if present',
    `list_name`   VARCHAR(100)  NULL     DEFAULT NULL              COMMENT 'Topic/list name for UI filtering',
    `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_suggestions_source_id` (`source_id`),
    KEY `idx_suggestions_source` (`source`),
    KEY `idx_suggestions_list_name` (`list_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
