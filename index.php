<?php

/* Set to UTC */

date_default_timezone_set('UTC');

/* Debug Mode */

error_reporting(E_ALL);
ini_set('display_errors','Off');
ini_set('error_log', dirname(__FILE__).'/error.log');
ini_set('log_errors', 'On');
    
/* Define */

define('ROOT',DIRNAME(__FILE__));
define('DS',DIRECTORY_SEPARATOR);

/* Start Session */

ini_set('session.cookie_secure',   '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();

/* Get Basic Details */

$controller = preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($_GET['c'] ?? '')) ?: 'home';
$action     = preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($_GET['a'] ?? '')) ?: 'index';

/* Composer Autoloader */

if (is_file(ROOT.DS.'vendor'.DS.'autoload.php')) {
	require_once ROOT.DS.'vendor'.DS.'autoload.php';
}

/* Include Libraries */

include_once ROOT.DS.'config.php';
include_once ROOT.DS.'libraries'.DS.'template.class.php';
include_once ROOT.DS.'libraries'.DS.'postmark.class.php';

$template = new Template($controller,$action);

include_once ROOT.DS.'libraries'.DS.'shared.php';

// -----------------------------------------------------------------------
// First-run check
// If the users table is empty this is a fresh install. Force the setup
// flow for every route except: users/setup, users/setpassword (so the
// password-set link works before any user exists), users/forgot, and cron.
// -----------------------------------------------------------------------
$firstRunBypassed = (
    ($controller === 'users' && in_array($action, ['setup', 'setpassword', 'forgot'], true))
    || $controller === 'cron'
);
if (!$firstRunBypassed) {
    $stmt = $dbh->prepare('SELECT COUNT(*) FROM users');
    $stmt->execute();
    if ((int) $stmt->fetchColumn() === 0) {
        header('Location: ' . u('users', 'setup'));
        exit;
    }
}

if (!defined('MINIMAL')) {
	/* Basic Bootstrapping */

	if (is_file(ROOT.DS.'controllers'.DS.$controller.'.php')) {

		include ROOT.DS.'controllers'.DS.$controller.'.php';

		if (function_exists($action)) {
			call_user_func($action);
		} else {
			if (function_exists('index')) {
				call_user_func('index');
			} else {
				/* 404 error here */
				error404();
			}
		}
		
		$template->render();

		} else {
		/* 404 error here */
		error404();
	}
}