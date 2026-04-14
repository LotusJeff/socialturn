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

// MySQL port — usually 3306
define('SERVERPORT', '3306');

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

// A random secret string used for internal security.
// Generate one with: php -r "echo bin2hex(random_bytes(32));"
define('SERVER_SALT', 'replace_with_64_char_random_hex_string');

// Number of pending queue entries below which the recycler refills the queue.
// Lower = more aggressive refilling. Recommended: 10–20.
define('RECYCLE_THRESHOLD_DEFAULT', 10);

// How many days ahead the queue population engine schedules posts.
// Recommended: 14–30.
define('RECYCLE_LOOKAHEAD_DAYS', 30);

// -----------------------------------------------------------------------
// Facebook / Instagram
// -----------------------------------------------------------------------
// 1. Go to https://developers.facebook.com/ and create an app.
// 2. Add the Facebook Login and Instagram Graph API products.
// 3. Required permissions: pages_manage_posts, pages_read_engagement,
//    instagram_basic, instagram_content_publish.
// 4. Copy the App ID and App Secret from App Settings > Basic.

define('FB_APPID', 'your_facebook_app_id');
define('FB_APPSECRET', 'your_facebook_app_secret');

// -----------------------------------------------------------------------
// Twitter / X
// -----------------------------------------------------------------------
// 1. Go to https://developer.twitter.com/ and create a project + app.
// 2. Set app permissions to "Read and Write".
// 3. Copy the API Key and API Key Secret from the app's Keys and Tokens page.

define('TWITTER_APIKEY', 'your_twitter_api_key');
define('TWITTER_APISECRET', 'your_twitter_api_secret');

// -----------------------------------------------------------------------
// Email — Postmark (required for sending team invitations)
// -----------------------------------------------------------------------
// 1. Sign up at https://postmarkapp.com/ (free tier available).
// 2. Create a Server and copy the Server API Token.
// 3. Verify your sending domain in Postmark before sending.

define('POSTMARKAPP_APIKEY', 'your_postmark_server_api_key');
