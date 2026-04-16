<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Suppress db() and authenticate() at the bottom of libraries/shared.php.
// Without this guard those two calls would either kill the process (db()
// calls exit on connection failure) or issue a redirect and exit
// (authenticate() when no session exists).
// ---------------------------------------------------------------------------
define('RUNNING_TESTS', true);

// ---------------------------------------------------------------------------
// Constants that config.php normally provides.
// All credential-shaped values use obvious test placeholders — they are
// never sent to any real API during PHPUnit runs.
// ---------------------------------------------------------------------------
define('BASE_URL',                   'http://localhost/');
define('STORAGE_DRIVER',             'local');
define('RECYCLE_THRESHOLD_DEFAULT',  10);
define('RECYCLE_LOOKAHEAD_DAYS',     30);
define('TWITTER_APIKEY',             'test_twitter_api_key');
define('TWITTER_APISECRET',          'test_twitter_api_secret');
define('META_APP_ID',                'test_meta_app_id');
define('META_APP_SECRET',            'test_meta_app_secret');
define('SERVER_SALT',                str_repeat('x', 32));

// ---------------------------------------------------------------------------
// Test database credentials — read from environment variables at runtime.
// Unit tests (tests/Unit/) never touch the database.
// Integration and Platform tests require these to be set.
//
// Set before running:
//   export TEST_DB_HOST=127.0.0.1
//   export TEST_DB_NAME=socialturn_test
//   export TEST_DB_USER=root
//   export TEST_DB_PASSWORD=secret
// ---------------------------------------------------------------------------
define('SERVERNAME', (string) (getenv('TEST_DB_HOST')     ?: '127.0.0.1'));
define('DBNAME',     (string) (getenv('TEST_DB_NAME')     ?: 'socialturn_test'));
define('DBUSERNAME', (string) (getenv('TEST_DB_USER')     ?: 'root'));
define('DBPASSWORD', (string) (getenv('TEST_DB_PASSWORD') ?: ''));

// ---------------------------------------------------------------------------
// Globals that shared.php and the authenticate() function reference.
// Routing through the 'cron' controller causes authenticate() to return
// immediately even if the RUNNING_TESTS guard were ever removed.
// ---------------------------------------------------------------------------
$controller = 'cron';
$action     = '';
$path       = [];

// ---------------------------------------------------------------------------
// Session — integration tests that assert on $_SESSION state need this.
// ---------------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---------------------------------------------------------------------------
// Composer autoloader — loads all SocialTurn\* and Tests\* namespaces.
// ---------------------------------------------------------------------------
require_once dirname(__DIR__) . '/vendor/autoload.php';

// ---------------------------------------------------------------------------
// Shared library — loads normalize_body() and other global helpers.
// db() and authenticate() are suppressed by the RUNNING_TESTS guard above.
// ---------------------------------------------------------------------------
require_once dirname(__DIR__) . '/libraries/shared.php';
