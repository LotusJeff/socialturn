-- ============================================================
-- Migration 026: admin_settings table
-- ============================================================
--
-- Stores all application configuration beyond DB credentials
-- and BASE_URL. These values replace constants that were
-- previously defined in config.php.
--
-- Fresh installs: already included in schema.sql — do not
-- run this file separately on a new install.
--
-- Upgrades from 0.9.x: run this migration once, then create
-- config.ini manually (see INSTALL.md).
--
-- ============================================================

CREATE TABLE IF NOT EXISTS `admin_settings` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `setting_key` VARCHAR(100)  NOT NULL                           COMMENT 'Constant name in snake_case (e.g. twitter_apikey)',
    `setting_val` TEXT          NULL DEFAULT NULL                  COMMENT 'Setting value; NULL treated as empty string by load_admin_settings()',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_admin_settings_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `admin_settings` (`setting_key`, `setting_val`) VALUES
('owner_email',                   ''),
('recycle_threshold_default',     '10'),
('recycle_lookahead_days',        '30'),
('schedule_min_posts',            '5'),
('twitter_apikey',                ''),
('twitter_apisecret',             ''),
('meta_app_id',                   ''),
('meta_app_secret',               ''),
('postmarkapp_api_key',           ''),
('postmarkapp_mail_from_address', ''),
('postmarkapp_mail_from_name',    '');
