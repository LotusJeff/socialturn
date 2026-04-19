<?php
declare(strict_types=1);

// CLI guard — refuse all HTTP requests
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit;
}

date_default_timezone_set('UTC');
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors',     '0');

define('ROOT', __DIR__);
define('DS',   DIRECTORY_SEPARATOR);

// boot.php — defines CONFIG_PATH pointing to socialturn.ini
if (!file_exists(ROOT . DS . 'boot.php')) {
    echo '[' . date('Y-m-d H:i:s') . '] CRON ERROR: boot.php not found. Is SocialTurn installed?' . PHP_EOL;
    exit(1);
}
require ROOT . DS . 'boot.php';

// socialturn.ini
if (!defined('CONFIG_PATH') || !file_exists(CONFIG_PATH) || !is_readable(CONFIG_PATH)) {
    echo '[' . date('Y-m-d H:i:s') . '] CRON ERROR: socialturn.ini not found or not readable at '
        . (defined('CONFIG_PATH') ? CONFIG_PATH : 'undefined path') . PHP_EOL;
    exit(1);
}

$ini = parse_ini_file(CONFIG_PATH, true);
if ($ini === false || empty($ini['socialturn'])) {
    echo '[' . date('Y-m-d H:i:s') . '] CRON ERROR: socialturn.ini could not be parsed.' . PHP_EOL;
    exit(1);
}

$cfg = $ini['socialturn'];
define('SERVERNAME',     (string) ($cfg['db_host']  ?? ''));
define('DBNAME',         (string) ($cfg['db_name']  ?? ''));
define('DBUSERNAME',     (string) ($cfg['db_user']  ?? ''));
define('DBPASSWORD',     (string) ($cfg['db_pass']  ?? ''));
define('BASE_URL',       rtrim((string) ($cfg['base_url'] ?? ''), '/') . '/');
define('STORAGE_DRIVER', 'local');

// $controller and $action are globals referenced by shared.php helpers
$controller = 'cron';
$action     = 'post';

// Composer autoloader
if (is_file(ROOT . DS . 'vendor' . DS . 'autoload.php')) {
    require_once ROOT . DS . 'vendor' . DS . 'autoload.php';
}

// shared.php — initialises $dbh, defines global helpers. No session needed.
require_once ROOT . DS . 'libraries' . DS . 'shared.php';

// Cron controller — defines post()
require ROOT . DS . 'controllers' . DS . 'cron.php';

// Run. post() echoes JSON and exits — output captured by crontab log redirect.
post();
