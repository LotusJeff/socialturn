<?php

/**
 * SocialTurn Configuration
 *
 * Copy this file to config.php and fill in your values.
 * config.php is excluded from git and must never be committed.
 *
 * To copy: cp config.sample.php config.php
 */

// -----------------------------------------------------------------------
// Database
// -----------------------------------------------------------------------

// MySQL hostname — usually 'localhost' for shared hosting
define('SERVERNAME', 'localhost');

// MySQL username
define('DBUSERNAME', 'your_db_username');

// MySQL password
define('DBPASSWORD', 'your_db_password');

// MySQL database name — must exist before running schema.sql
define('DBNAME', 'your_db_name');

// -----------------------------------------------------------------------
// Application
// -----------------------------------------------------------------------

// Full public URL to your installation, with trailing slash.
// Example: 'https://social.example.com/'
define('BASE_URL', 'https://yoursite.com/');

// Number of pending queue entries below which the recycler refills the queue.
// Lower = more aggressive refilling. Recommended: 10–20.
define('RECYCLE_THRESHOLD_DEFAULT', 10);

// Fallback number of days ahead the queue population engine schedules posts
// when no per-account setting exists in account_settings.recycle_lookahead_days.
// Per-account settings take precedence over this value. Recommended: 14–30.
define('RECYCLE_LOOKAHEAD_DAYS', 30);

// -----------------------------------------------------------------------
// Facebook / Instagram
// -----------------------------------------------------------------------
// 1. Go to https://developers.facebook.com/ and create an app.
// 2. Add the Facebook Login and Instagram Graph API products.
// 3. Required permissions: pages_manage_posts, pages_read_engagement,
//    instagram_basic, instagram_content_publish.
// 4. Copy the App ID and App Secret from App Settings > Basic.

define('META_APP_ID',     'your_facebook_app_id');
define('META_APP_SECRET', 'your_facebook_app_secret');

// -----------------------------------------------------------------------
// Twitter / X
// -----------------------------------------------------------------------
// 1. Go to https://developer.twitter.com/ and create a project + app.
// 2. Set app permissions to "Read and Write".
// 3. Copy the API Key and API Key Secret from the app's Keys and Tokens page.

define('TWITTER_APIKEY', 'your_twitter_api_key');
define('TWITTER_APISECRET', 'your_twitter_api_secret');

// -----------------------------------------------------------------------
// Storage
// -----------------------------------------------------------------------
// Controls where uploaded and queued images are stored.
// 'local' — reads/writes the /images/ directory on this server (default).
//           No additional packages required.
// 's3'    — reads/writes AWS S3. Requires: composer require aws/aws-sdk-php
//           Uncomment and fill in the S3_* constants below when using S3.

define('STORAGE_DRIVER', 'local');

// AWS S3 — only required when STORAGE_DRIVER = 's3'
// define('S3_BUCKET', 'your-bucket-name');
// define('S3_REGION', 'us-east-1');
// define('S3_KEY',    'your-iam-access-key-id');
// define('S3_SECRET', 'your-iam-secret-access-key');

// -----------------------------------------------------------------------
// Email — Postmark (https://postmarkapp.com)
// Free tier: 100 emails/month — sufficient for a self-hosted single-tenant install.
// 1. Sign up at postmarkapp.com
// 2. Create a Server
// 3. Copy the Server API Token below
// Alternative providers: Mailjet (200 emails/day free), Resend (3,000 emails/month free).
// To use an alternative, replace libraries/postmark.class.php with your provider's PHP
// library and update the send calls in controllers/users.php and controllers/team.php
// -----------------------------------------------------------------------

// Server API Token from your Postmark Server's API Tokens tab.
define('POSTMARKAPP_API_KEY', 'your_postmark_server_api_token');

// The email address all outbound mail is sent from.
// Must match a verified sender signature or domain in Postmark.
define('POSTMARKAPP_MAIL_FROM_ADDRESS', 'noreply@yourdomain.com');

// Display name shown in the From field of outbound emails.
define('POSTMARKAPP_MAIL_FROM_NAME', 'SocialTurn');

// -----------------------------------------------------------------------
// Owner
// -----------------------------------------------------------------------
// Owner email — used for initial setup only.
// The owner account password is set via email link and stored in the database.
// Never store a password in this file.
define('OWNER_EMAIL', 'your@email.com');
