-- ============================================================
-- Migration 017 -- Drop suggestions table
-- ============================================================
--
-- The suggestions system has been intentionally removed.
-- Content enters the system via manual entry or CSV import in v1.0.
--
-- Run against an existing database to upgrade.
-- Fresh installs use the updated schema.sql which no longer
-- includes the suggestions table.
--
-- ============================================================

DROP TABLE IF EXISTS `suggestions`;
