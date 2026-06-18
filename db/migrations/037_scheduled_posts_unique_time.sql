-- ============================================================
-- Migration 037: Unique constraint on scheduled_posts
--               (connected_platform_id, scheduled_time)
-- ============================================================
-- Prevents concurrent queue population runs from creating
-- duplicate rows for the same platform connection and time slot.
-- The application-level deduplication in QueuePopulationService
-- remains in place; this adds a DB-level guard.
--
-- Behaviour on collision: the full INSERT batch in
-- insertScheduledPosts() rolls back if any row in the batch
-- collides with an existing row. The first successful populate
-- run covers the queue; the second run's PDOException is caught
-- by QueuePopulationService and logged by RecycleService.
--
-- Safe to run: zero duplicate (connected_platform_id,
-- scheduled_time) rows confirmed in the live database before
-- adding this constraint.
-- ============================================================

ALTER TABLE `scheduled_posts`
    ADD UNIQUE KEY `uq_scheduled_posts_platform_time`
        (`connected_platform_id`, `scheduled_time`);
