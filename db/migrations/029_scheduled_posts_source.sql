-- Migration 029: Add source column to scheduled_posts
-- Identifies how each pending row was created:
--   queue      = auto-populated by the queue population engine
--   share_now  = immediate send via Share Now or Send Now
--   scheduled  = user-chosen future datetime via Future Schedule
-- DEFAULT 'queue' correctly classifies all existing rows with no backfill.
-- flush() and schedule-change cascades now filter to source = 'queue' only,
-- preserving user-intentional rows across both operations.

ALTER TABLE `scheduled_posts`
  ADD COLUMN `source` ENUM('queue','share_now','scheduled') NOT NULL DEFAULT 'queue'
      COMMENT 'Origin: queue=auto-populated, share_now=immediate send, scheduled=user-chosen future time'
      AFTER `status`;
