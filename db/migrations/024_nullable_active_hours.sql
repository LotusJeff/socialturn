-- Migration 024: Allow NULL for active_hours_start and active_hours_end
-- These columns are only used when schedule_type = interval.
-- NULL indicates the account uses time_specific scheduling.
ALTER TABLE account_schedules
    MODIFY active_hours_start TINYINT UNSIGNED NULL DEFAULT NULL,
    MODIFY active_hours_end   TINYINT UNSIGNED NULL DEFAULT NULL;
