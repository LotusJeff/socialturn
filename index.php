<?php

date_default_timezone_set('UTC');

error_reporting(E_ALL);
ini_set('display_errors', 'Off');
ini_set('error_log', dirname(__FILE__) . '/error.log');
ini_set('log_errors', 'On');

/* Define */

define('ROOT', DIRNAME(__FILE__));
define('DS',   DIRECTORY_SEPARATOR);

/* Require boot.php — defines CONFIG_PATH pointing to socialturn.ini */

if (!file_exists(ROOT . DS . 'boot.php')) {
    if (file_exists(ROOT . DS . 'install.php')) {
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $dir   = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
        header('Location: ' . $proto . '://' . $host . $dir . '/install.php');
        exit;
    }
    http_response_code(503);
    die('SocialTurn is not configured. Run install.php to set up the application.');
}

require ROOT . DS . 'boot.php';

/* Verify CONFIG_PATH points to a readable socialturn.ini */

if (!defined('CONFIG_PATH') || !file_exists(CONFIG_PATH) || !is_readable(CONFIG_PATH)) {
    if (file_exists(ROOT . DS . 'install.php')) {
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $dir   = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
        header('Location: ' . $proto . '://' . $host . $dir . '/install.php');
        exit;
    }
    http_response_code(503);
    die('socialturn.ini not found or not readable at the path defined in boot.php. Restore the file or re-run install.php.');
}

$ini = parse_ini_file(CONFIG_PATH, true);
if ($ini === false || empty($ini['socialturn'])) {
    http_response_code(503);
    die('socialturn.ini could not be parsed. Check the file format.');
}

$cfg = $ini['socialturn'];
define('SERVERNAME', (string) ($cfg['db_host']  ?? ''));
define('DBNAME',     (string) ($cfg['db_name']  ?? ''));
define('DBUSERNAME', (string) ($cfg['db_user']  ?? ''));
define('DBPASSWORD', (string) ($cfg['db_pass']  ?? ''));
define('BASE_URL',   rtrim((string) ($cfg['base_url'] ?? ''), '/') . '/');
define('STORAGE_DRIVER', 'local');

/* Start Session */

ini_set('session.cookie_secure',   '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();

/* Get Basic Details */

$controller = preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($_GET['c'] ?? '')) ?: 'home';
$action     = preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($_GET['a'] ?? '')) ?: 'index';

/* Composer Autoloader */

if (is_file(ROOT . DS . 'vendor' . DS . 'autoload.php')) {
    require_once ROOT . DS . 'vendor' . DS . 'autoload.php';
}

/* Include Libraries */

include_once ROOT . DS . 'libraries' . DS . 'template.class.php';
include_once ROOT . DS . 'libraries' . DS . 'postmark.class.php';

$template = new Template($controller, $action);

include_once ROOT . DS . 'libraries' . DS . 'shared.php';

/* install.php security warning — shown to admin users on every page */

if (
    file_exists(ROOT . DS . 'install.php')
    && isset($_SESSION['user']['type'])
    && (int) $_SESSION['user']['type'] === 1
) {
    $template->set('installPhpWarning', true);
}

if (!defined('MINIMAL')) {

    if (is_file(ROOT . DS . 'controllers' . DS . $controller . '.php')) {

        include ROOT . DS . 'controllers' . DS . $controller . '.php';

        if (function_exists($action)) {
            call_user_func($action);
        } else {
            if (function_exists('index')) {
                call_user_func('index');
            } else {
                error404();
            }
        }

        $template->render();

    } else {
        error404();
    }
}
