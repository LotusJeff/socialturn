-- Migration 035: add connected_platform_id to oauth_states for in-place reconnect
-- When set, the callback updates the existing connected_platforms row rather than inserting a new one.

ALTER TABLE `oauth_states`
    ADD COLUMN `connected_platform_id` INT UNSIGNED NULL DEFAULT NULL
        COMMENT 'When set, this handshake reconnects an existing connected_platforms row in place; NULL = fresh connection',
    ADD CONSTRAINT `fk_oauth_states_connected_platform`
        FOREIGN KEY (`connected_platform_id`) REFERENCES `connected_platforms` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE;
