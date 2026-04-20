-- ============================================================
-- SocialTurn -- Fresh Install Schema
-- ============================================================
--
-- Run this on a clean database for a new installation.
-- For upgrades from an existing install, run db/migrations/
-- in numbered order instead of this file.
--
-- Requires MySQL 8.0+ with InnoDB and utf8mb4 support.
--
-- ============================================================
-- Queue Engine Architecture
-- ============================================================
--
-- The content library (posts) and the queue (scheduled_posts)
-- are separate concerns. The queue population engine is triggered
-- by cron when the pending queue depth for an account falls below
-- account_settings.recycle_threshold.
--
-- Prerequisites for an account to be processed by the engine:
--   accounts.is_active  = 1  (not archived)
--   accounts.is_posting = 1  (actively posting, not paused)
--
-- Slot calculation is driven by account_schedules.schedule_type:
--
--   interval:
--     Calculates future slots from account_schedules.interval
--     and the active_hours_start / active_hours_end window,
--     interpreted in account_schedules.timezone.
--     All calculated times are snapped to the nearest 15-minute
--     boundary (:00, :15, :30, :45) to prevent schedule drift.
--
--   time_specific:
--     Reads account_schedule_slots WHERE account_id = ? AND is_active = 1.
--     Uses account_schedules.timezone for date construction.
--
-- Tag appending at send time:
--   accounts.default_tags (JSON array of strings without # prefix)
--   are appended to posts.body in array order until the platform
--   character limit is reached.
--   Platform limits: Twitter 280, Instagram 2200, Facebook 63206
--
-- Column naming note:
--   accounts.name         = operator-defined internal label
--                           (e.g. "Brand X -- Morning Posts")
--   accounts.display_name = platform profile name pulled from the API
--                           (e.g. "Brand X Official Page")
--
-- Credential architecture:
--   Layer 1 (app credentials): config.php only -- never in the database
--   Layer 2 (account credentials): connected_platforms table
--
-- ============================================================

SET NAMES 'utf8mb4';
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- companies
-- Top-level tenant. Single-tenant installs have exactly one row.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `companies` (
    `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(255)  NOT NULL                           COMMENT 'Organization or team name',
    `active`     TINYINT(1)    NOT NULL DEFAULT 1                 COMMENT '0=suspended, 1=active',
    `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- users
-- Authentication and authorization.
-- type=1: admin (full access to all accounts)
-- type=100: team member (limited to accounts in users_accounts)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id`         INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `company_id` INT UNSIGNED     NOT NULL                        COMMENT 'Owning company',
    `email`      VARCHAR(255)     NOT NULL                        COMMENT 'Login email address',
    `password`   VARCHAR(255)     NOT NULL                        COMMENT 'bcrypt hash - never plaintext',
    `type`       TINYINT UNSIGNED NOT NULL DEFAULT 100            COMMENT '1=admin, 100=team member',
    `active`      TINYINT(1)       NOT NULL DEFAULT 1              COMMENT '0=deactivated, 1=active',
    `last_login`  DATETIME         NULL     DEFAULT NULL           COMMENT 'Set on every successful login — displayed in team dashboard',
    `created_at`  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_email` (`email`),
    KEY `idx_users_company_id` (`company_id`),
    KEY `idx_users_active` (`active`),
    CONSTRAINT `fk_users_company`
        FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- invites
-- Secure one-time team invitation tokens.
-- Created by admins; consumed when a new user registers.
-- Also created in migration 001 for upgrades from old installs.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `invites` (
    `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `company_id` INT UNSIGNED  NOT NULL                           COMMENT 'Company that issued the invite',
    `email`      VARCHAR(255)  NOT NULL                           COMMENT 'Invited email address',
    `token`      CHAR(64)      NOT NULL                           COMMENT 'bin2hex(random_bytes(32))',
    `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the invite was issued',
    `used_at`    DATETIME      NULL     DEFAULT NULL              COMMENT 'When consumed; NULL=unused',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_invites_token` (`token`),
    KEY `idx_invites_company_email` (`company_id`, `email`),
    CONSTRAINT `fk_invites_company`
        FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- connected_platforms
-- Company-level OAuth token store. One row per real-world
-- page/profile. Accounts reference these via connected_platform_id.
-- Layer 2 of the credential architecture; Layer 1 is in config.php.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `connected_platforms` (
    `id`                  INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `company_id`          INT UNSIGNED   NOT NULL                           COMMENT 'Owning company',
    `platform`            ENUM('twitter','facebook','instagram') NOT NULL   COMMENT 'Platform identifier',
    `platform_account_id` VARCHAR(100)   NOT NULL                           COMMENT 'Platform own ID: FB Page ID, Twitter user ID, IG account ID',
    `platform_name`       VARCHAR(255)   NULL     DEFAULT NULL              COMMENT 'Display name as it appears on the platform',
    `platform_username`   VARCHAR(255)   NULL     DEFAULT NULL              COMMENT 'Handle or slug - display only',
    `platform_avatar_url` VARCHAR(512)   NULL     DEFAULT NULL              COMMENT 'Profile image URL cached for UI; may become stale',
    `access_token`        TEXT           NOT NULL                           COMMENT 'OAuth access token - never log, never expose in views or responses',
    `token_secret`        VARCHAR(512)   NULL     DEFAULT NULL              COMMENT 'OAuth 1.0a token secret (Twitter only); NULL for OAuth 2.0 platforms',
    `token_expires_at`    DATETIME       NULL     DEFAULT NULL              COMMENT 'NULL=never expires (Twitter); set for Facebook/Instagram 60-day tokens',
    `is_active`           TINYINT(1)     NOT NULL DEFAULT 1                 COMMENT '0=disconnected or expired, 1=active',
    `created_at`          DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When first connected',
    `updated_at`          DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last token refresh',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_connected_platforms_company_platform_account` (`company_id`, `platform`, `platform_account_id`),
    KEY `idx_connected_platforms_company_id` (`company_id`),
    KEY `idx_connected_platforms_is_active` (`is_active`),
    KEY `idx_connected_platforms_token_expires_at` (`token_expires_at`),
    CONSTRAINT `fk_connected_platforms_company`
        FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- accounts
-- A posting identity. One per platform connection.
-- Must reference a connected_platform before it can be created.
-- Owns the content library, queue, and schedule for that platform.
--
-- name:         Operator-defined internal label, e.g. "Brand X -- Marketing"
-- display_name: Platform profile name pulled from API, e.g. "Brand X Official Page"
-- is_posting:   1=actively scheduling and sending; 0=paused or in setup.
--               Separate from is_active. Queue engine checks this before
--               processing an account.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `accounts` (
    `id`                    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `company_id`            INT UNSIGNED  NOT NULL                           COMMENT 'Owning company',
    `connected_platform_id` INT UNSIGNED  NOT NULL                           COMMENT 'Required: must exist before account is created',
    `name`                  VARCHAR(255)  NOT NULL                           COMMENT 'Operator-defined internal label (e.g. "Brand X - Marketing")',
    `display_name`          VARCHAR(255)  NULL     DEFAULT NULL              COMMENT 'Platform profile name pulled from API (e.g. "Brand X Official Page")',
    `default_tags`          JSON          NULL     DEFAULT NULL              COMMENT 'Ordered array of tag strings without # prefix, e.g. ["marketing","saas"]. Appended at send time in array order until platform character limit is reached (Twitter 280, Instagram 2200, Facebook 63206)',
    `is_posting`            TINYINT(1)    NOT NULL DEFAULT 0                 COMMENT '1=actively scheduling and sending; 0=paused or in setup. Content cannot be queued until is_posting=1',
    `is_active`             TINYINT(1)    NOT NULL DEFAULT 1                 COMMENT '0=archived (hidden from all views), 1=exists',
    `dynamic_images_enabled` TINYINT(1)  NOT NULL DEFAULT 0                 COMMENT '1 = generate image from base_image_filename when post has no image; 0 = text-only posts allowed',
    `base_image_filename`   VARCHAR(255)  NULL     DEFAULT NULL              COMMENT 'Base image in originals/ used by ImageService::generateFromTemplate(); NULL = no template configured',
    `created_at`            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_accounts_company_id` (`company_id`),
    KEY `idx_accounts_connected_platform_id` (`connected_platform_id`),
    KEY `idx_accounts_is_active` (`is_active`),
    KEY `idx_accounts_is_posting` (`is_posting`),
    KEY `idx_accounts_company_active_posting` (`company_id`, `is_active`, `is_posting`),
    CONSTRAINT `fk_accounts_company`
        FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_accounts_connected_platform`
        FOREIGN KEY (`connected_platform_id`) REFERENCES `connected_platforms` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- users_accounts
-- Maps team members to accounts they are authorized to access.
-- Admin users (type=1) have implicit access; they do not appear here.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users_accounts` (
    `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `company_id` INT UNSIGNED  NOT NULL                           COMMENT 'Denormalized for fast permission queries',
    `user_id`    INT UNSIGNED  NOT NULL                           COMMENT 'The team member being granted access',
    `account_id` INT UNSIGNED  NOT NULL                           COMMENT 'The account being granted access to',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_accounts_user_account` (`user_id`, `account_id`),
    KEY `idx_users_accounts_company_id` (`company_id`),
    KEY `idx_users_accounts_user_id` (`user_id`),
    KEY `idx_users_accounts_account_id` (`account_id`),
    CONSTRAINT `fk_users_accounts_company`
        FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_users_accounts_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_users_accounts_account`
        FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- oauth_states
-- Temporary OAuth handshake state. Replaces SESSION storage in
-- the platform connection flow. Purge rows older than 15 minutes.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `oauth_states` (
    `id`                   INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `state_key`            CHAR(64)      NOT NULL                           COMMENT 'Random key for this OAuth session; passed as state parameter',
    `platform`             ENUM('twitter','facebook','instagram') NOT NULL  COMMENT 'Platform being connected',
    `account_id`           INT UNSIGNED  NOT NULL                           COMMENT 'Account being connected to',
    `user_id`              INT UNSIGNED  NOT NULL                           COMMENT 'User who initiated the connection',
    `request_token`        VARCHAR(512)  NULL     DEFAULT NULL              COMMENT 'OAuth 1.0a request token (Twitter only)',
    `request_token_secret` VARCHAR(512)  NULL     DEFAULT NULL              COMMENT 'OAuth 1.0a request token secret (Twitter only)',
    `created_at`           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Used to identify and purge rows older than 15 minutes',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_oauth_states_state_key` (`state_key`),
    KEY `idx_oauth_states_account_id` (`account_id`),
    KEY `idx_oauth_states_created_at` (`created_at`),
    CONSTRAINT `fk_oauth_states_account`
        FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_oauth_states_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- account_schedules
-- One schedule definition per account. schedule_type determines
-- whether the queue engine uses interval or time-specific slots.
-- active_hours and interval columns are only used for schedule_type=interval.
-- Slot times for interval mode are snapped to the nearest 15-minute
-- boundary (:00, :15, :30, :45) to prevent schedule drift.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `account_schedules` (
    `id`                      INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `account_id`              INT UNSIGNED     NOT NULL                           COMMENT 'One schedule per account',
    `schedule_type`           ENUM('interval','time_specific') NOT NULL           COMMENT 'interval: uses interval+active_hours; time_specific: uses account_schedule_slots',
    `interval`                ENUM('every_15min','every_30min','every_hour','every_2hr','every_4hr','every_8hr','custom') NULL DEFAULT NULL COMMENT 'Posting frequency; only used when schedule_type=interval',
    `custom_interval_minutes` SMALLINT UNSIGNED NULL     DEFAULT NULL             COMMENT 'Minutes between posts; only used when interval=custom',
    `active_hours_start`      TINYINT UNSIGNED  NULL     DEFAULT NULL             COMMENT 'Hour (0-23) posting window opens; only used when schedule_type=interval; interpreted in timezone',
    `active_hours_end`        TINYINT UNSIGNED  NULL     DEFAULT NULL             COMMENT 'Hour (0-23) posting window closes; only used when schedule_type=interval; interpreted in timezone',
    `timezone`                VARCHAR(64)       NOT NULL DEFAULT 'UTC'            COMMENT 'PHP timezone string (e.g. America/New_York); applies to all schedule calculations',
    `created_at`              DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`              DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_account_schedules_account_id` (`account_id`),
    CONSTRAINT `fk_account_schedules_account`
        FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- account_schedule_slots
-- Exact time-of-day slots for time_specific scheduling.
-- Only read when account_schedules.schedule_type = time_specific.
-- Slots fire every day; no day-of-week control.
-- Times are interpreted in account_schedules.timezone.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `account_schedule_slots` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `account_id`  INT UNSIGNED  NOT NULL                           COMMENT 'Owning account',
    `time_of_day` TIME          NOT NULL                           COMMENT 'Exact post time e.g. 07:15:00; interpreted in account_schedules.timezone',
    `is_active`   TINYINT(1)    NOT NULL DEFAULT 1                 COMMENT '0=skipped by queue engine, 1=active',
    `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_account_schedule_slots_account_time` (`account_id`, `time_of_day`),
    KEY `idx_account_schedule_slots_account_id` (`account_id`),
    CONSTRAINT `fk_account_schedule_slots_account`
        FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- account_settings
-- Per-account queue engine configuration.
-- One row per account; seeded from config.php defaults at creation.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `account_settings` (
    `id`                     INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `account_id`             INT UNSIGNED     NOT NULL                           COMMENT 'One settings row per account',
    `recycle_threshold`      SMALLINT UNSIGNED NOT NULL DEFAULT 10               COMMENT 'Pending queue depth below which the recycler runs',
    `recycle_lookahead_days` SMALLINT UNSIGNED   NOT NULL DEFAULT 30             COMMENT 'How many days ahead the queue engine schedules posts',
    `scheduling_enabled`     TINYINT(1) UNSIGNED NOT NULL DEFAULT 0              COMMENT 'Master toggle — queue population engine runs for this account only when 1',
    `created_at`             DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`             DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_account_settings_account_id` (`account_id`),
    CONSTRAINT `fk_account_settings_account`
        FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- posts
-- Content library. Master record for all postable content.
-- The queue engine draws from this table to populate scheduled_posts.
-- is_recyclable=1: re-enters queue after posting.
-- is_recyclable=0: sent once, then is_active set to 0 automatically.
-- internal_note: operator-facing only, never sent to any platform.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `posts` (
    `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `account_id`     INT UNSIGNED  NOT NULL                           COMMENT 'Which account this content belongs to',
    `body`           TEXT          NOT NULL                           COMMENT 'The post text',
    `body_normalized` VARCHAR(280) NOT NULL DEFAULT ''                COMMENT 'Normalized body fingerprint for duplicate detection — never displayed',
    `attributed_to`  VARCHAR(255)  NULL     DEFAULT NULL              COMMENT 'Attribution/author - appended as "- Author" after post body; affects image overlay layout in ImageService. Never included in image text - post body and attribution are overlaid on image, tags are text-only.',
    `post_tags`      VARCHAR(255)  NULL     DEFAULT NULL              COMMENT 'Post-specific hashtags appended after attribution and before account default tags',
    `image_filename` VARCHAR(255)  NULL     DEFAULT NULL              COMMENT 'Filename within images/; NULL=text-only post',
    `is_recyclable`  TINYINT(1)    NOT NULL DEFAULT 1                 COMMENT '1=re-enters queue after posting; 0=sent once then deactivated',
    `is_active`      TINYINT(1)    NOT NULL DEFAULT 1                 COMMENT '1=eligible for queue population; 0=excluded from all queues',
    `internal_note`  TEXT          NULL     DEFAULT NULL              COMMENT 'Operator note - never sent, never shown publicly, visible in UI only',
    `created_by`     INT UNSIGNED  NOT NULL                           COMMENT 'User who created this post',
    `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_posts_account_id` (`account_id`),
    KEY `idx_posts_body_normalized` (`account_id`, `body_normalized`),
    KEY `idx_posts_queue_filter` (`account_id`, `is_active`, `is_recyclable`),
    CONSTRAINT `fk_posts_account`
        FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_posts_created_by`
        FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- scheduled_posts
-- The posting queue. Each row is one scheduled instance of a post
-- on a specific platform at a specific datetime.
-- Created by the queue population engine; consumed by cron.
-- locked_at: set atomically by cron to prevent double-posting
--   when two cron runs overlap; cleared on failure.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `scheduled_posts` (
    `id`                    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `connected_platform_id` INT UNSIGNED  NOT NULL                           COMMENT 'Which platform connection to post through',
    `post_id`               INT UNSIGNED  NOT NULL                           COMMENT 'Which content to post',
    `scheduled_time`        DATETIME      NOT NULL                           COMMENT 'When this post should be sent',
    `status`                ENUM('pending','posted','failed','skipped') NOT NULL DEFAULT 'pending' COMMENT 'Cron only processes pending rows',
    `source`                ENUM('queue','share_now','scheduled') NOT NULL DEFAULT 'queue' COMMENT 'Origin: queue=auto-populated, share_now=immediate send, scheduled=user-chosen future time',
    `final_body`            TEXT          NOT NULL                           COMMENT 'Post body with hashtags appended by TagAppenderService at queue population time; sent verbatim by cron',
    `final_image_filename`  VARCHAR(255)  NULL     DEFAULT NULL              COMMENT 'Processed or generated image filename ready for posting; NULL = text-only post',
    `locked_at`             DATETIME      NULL     DEFAULT NULL              COMMENT 'Set atomically by cron when claiming a row; prevents double-posting on overlapping runs',
    `created_at`            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_scheduled_posts_platform_status_time` (`connected_platform_id`, `status`, `scheduled_time`),
    KEY `idx_scheduled_posts_post_platform_status` (`post_id`, `connected_platform_id`, `status`),
    CONSTRAINT `fk_scheduled_posts_connected_platform`
        FOREIGN KEY (`connected_platform_id`) REFERENCES `connected_platforms` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_scheduled_posts_post`
        FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- post_history
-- Immutable audit log. One row per post attempt (success or failure).
-- Key columns are denormalized so the log survives source record deletion.
-- Never updated after insert -- only appended.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `post_history` (
    `id`                    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `connected_platform_id` INT UNSIGNED  NOT NULL                           COMMENT 'FK preserved for dashboard queries while connection exists',
    `post_id`               INT UNSIGNED  NOT NULL                           COMMENT 'FK preserved while post exists',
    `scheduled_post_id`     INT UNSIGNED  NOT NULL                           COMMENT 'One history row per queue entry',
    `platform`              ENUM('twitter','facebook','instagram') NOT NULL  COMMENT 'Denormalized - survives connected_platform deletion',
    `platform_account_id`   VARCHAR(100)  NOT NULL                           COMMENT 'Denormalized page/profile ID at time of posting',
    `body_snapshot`         TEXT          NOT NULL                           COMMENT 'Exact text as sent; unaffected by future edits to the source post',
    `image_filename`        VARCHAR(255)  NULL     DEFAULT NULL              COMMENT 'Image filename at time of posting',
    `platform_post_id`      VARCHAR(100)  NULL     DEFAULT NULL              COMMENT 'ID returned by platform API (tweet ID, FB post ID, IG media ID); NULL on failure',
    `status`                ENUM('posted','failed') NOT NULL                 COMMENT 'Outcome of the post attempt',
    `error_message`         TEXT          NULL     DEFAULT NULL              COMMENT 'Platform error detail when status=failed; NULL on success',
    `posted_at`             DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When cron ran this post attempt',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_post_history_scheduled_post_id` (`scheduled_post_id`),
    KEY `idx_post_history_connected_platform_id` (`connected_platform_id`),
    KEY `idx_post_history_post_id` (`post_id`),
    KEY `idx_post_history_status` (`status`),
    KEY `idx_post_history_posted_at` (`posted_at`),
    CONSTRAINT `fk_post_history_connected_platform`
        FOREIGN KEY (`connected_platform_id`) REFERENCES `connected_platforms` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_post_history_post`
        FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_post_history_scheduled_post`
        FOREIGN KEY (`scheduled_post_id`) REFERENCES `scheduled_posts` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- activity_log
-- Rolling operational event log — purged to 48 hours by cron.
-- Records cron runs, post outcomes, token events, and queue
-- population. NOT a permanent audit trail; post_history is.
-- Tokens must never appear in message or context columns.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `activity_log` (
    `id`                    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `company_id`            INT UNSIGNED  NOT NULL                           COMMENT 'Owning company',
    `account_id`            INT UNSIGNED  NULL     DEFAULT NULL              COMMENT 'Account context; NULL for company-level events (e.g. cron_run)',
    `connected_platform_id` INT UNSIGNED  NULL     DEFAULT NULL              COMMENT 'Platform connection context; NULL when not applicable',
    `event_type`            ENUM(
                                'cron_run',
                                'post_success',
                                'post_failure',
                                'token_refresh',
                                'token_verify',
                                'queue_populate',
                                'connection_test'
                            ) NOT NULL                                       COMMENT 'Structured event type for filtering',
    `message`               TEXT          NOT NULL                           COMMENT 'Human-readable event description',
    `context`               JSON          NULL     DEFAULT NULL              COMMENT 'Structured event data (counts, IDs, errors); never include tokens',
    `created_at`            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_activity_log_company_time`   (`company_id`,            `created_at`),
    INDEX `idx_activity_log_account_time`   (`account_id`,            `created_at`),
    INDEX `idx_activity_log_platform_time`  (`connected_platform_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- admin_settings
-- Application configuration store. Loaded at bootstrap by
-- load_admin_settings() in libraries/shared.php and exposed
-- as PHP define() constants. Replaces constants that were
-- previously defined in config.php.
-- DB credentials and BASE_URL are stored in config.ini only.
-- ------------------------------------------------------------
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
('postmarkapp_mail_from_name',    ''),
('notify_post_failure',           '1'),
('notify_recap_frequency',        'weekly'),
('notify_recipient_email',        ''),
('notify_recap_last_sent',        '');

SET FOREIGN_KEY_CHECKS = 1;
