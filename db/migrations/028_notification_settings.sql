-- Migration 028: Add notification settings to admin_settings
--
-- Adds four keys for email notifications:
--   notify_post_failure   — '1'=send alert on post failure, '0'=off
--   notify_recap_frequency — 'weekly'|'daily'|'never'
--   notify_recipient_email — override address; '' falls back to owner_email
--   notify_recap_last_sent — UTC datetime of last recap send; '' = never sent
--
-- All four are loaded at bootstrap by load_admin_settings() as PHP constants.
-- Requires: admin_settings table (Migration 026).

INSERT IGNORE INTO `admin_settings` (`setting_key`, `setting_val`) VALUES
('notify_post_failure',    '1'),
('notify_recap_frequency', 'weekly'),
('notify_recipient_email', ''),
('notify_recap_last_sent', '');
