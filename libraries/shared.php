<?php

function u(string $controller, string $action = 'index', array $params = []): string {
    $qs = array_merge(['c' => $controller, 'a' => $action], $params);
    return BASE_URL . 'index.php?' . http_build_query($qs);
}

/**
 * Validates and computes pagination values from the current GET request.
 *
 * Returns [$page, $perPage, $offset, $totalPages].
 * per_page is clamped to 25|50|100; page is clamped to 1..$totalPages.
 */
function pagination_calc(int $total): array
{
    $allowed    = [25, 50, 100];
    $rawPerPage = (int) ($_GET['per_page'] ?? 50);
    $perPage    = in_array($rawPerPage, $allowed, true) ? $rawPerPage : 50;
    $total      = max(0, $total);
    $totalPages = max(1, (int) ceil($total / $perPage));
    $page       = max(1, min((int) ($_GET['page'] ?? 1), $totalPages));
    $offset     = ($page - 1) * $perPage;
    return [$page, $perPage, $offset, $totalPages];
}

/**
 * Returns true when a valid user session exists.
 *
 * Accepts both company_id (new session key, set by setpassword/login going
 * forward) and companyid (legacy key, set by the old validate() function)
 * so neither breaks while validate() is still in place. Remove the
 * companyid fallback once Section 4 replaces validate() entirely.
 */
function isLoggedIn(): bool {
	return !empty($_SESSION['user']['loggedin'])
		&& !empty($_SESSION['user']['email'])
		&& (!empty($_SESSION['user']['company_id']) || !empty($_SESSION['user']['companyid']))
		&& !empty($_SESSION['user']['type']);
}

function authenticate($force = 0) {
	global $controller;
	global $action;

	// Routes that never require authentication
	$unauthenticatedActions = ['login', 'validate', 'invite', 'register', 'setpassword', 'forgot'];
	if ($controller === 'users' && in_array($action, $unauthenticatedActions, true)) {
		return;
	}
	if ($controller === 'cron') {
		return;
	}

	// Admin-only routes — type=1 required
	$adminOnlyControllers = ['team', 'accounts', 'connect', 'settings'];
	if (in_array($controller, $adminOnlyControllers, true) && isLoggedIn()) {
		if ((int) ($_SESSION['user']['type'] ?? 999) !== 1) {
			header('Location: ' . u('oops', 'permissions'));
			exit;
		}
	}

	if (!isLoggedIn()) {
		// Store the attempted URL so login() can redirect back after success.
		$_SESSION['redirect_after_login'] = getLink();
		header('Location: ' . u('users', 'login'));
		exit;
	}
}

/**
 * Returns the current session CSRF token, generating one if not yet set.
 * The token is per-session — it is not regenerated on every GET request.
 * Call csrf_regenerate() explicitly at login and logout.
 */
function csrf_token(): string {
	if (empty($_SESSION['csrf_token'])) {
		$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
	}
	return $_SESSION['csrf_token'];
}

/**
 * Replaces the current CSRF token with a new one.
 * Call at login success and logout.
 */
function csrf_regenerate(): void {
	$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * Returns true if $_POST['csrf_token'] matches the session token.
 * Uses hash_equals() to prevent timing attacks.
 */
function csrf_validate(): bool {
	return !empty($_POST['csrf_token'])
		&& !empty($_SESSION['csrf_token'])
		&& hash_equals($_SESSION['csrf_token'], (string) $_POST['csrf_token']);
}

function error404() {
	header('Location: ' . u('oops', 'notfound'));
	exit;
}

function checkPermission($permission) {
	if ($_SESSION['user']['type'] > $permission) {
		header('Location: ' . u('oops', 'permissions'));
		exit;
	}
}

function hasPermission($permission) {
	if ($_SESSION['user']['type'] > $permission) {
		return false;
	}

	return true;
}

/**
 * Verifies the current user may access a specific account.
 * Admins (type=1) pass silently. Team members (type=100) are checked
 * against users_accounts. Redirects to oops/permissions on failure.
 */
function authorizeAccount(int $accountId): void {
	if ((int) ($_SESSION['user']['type'] ?? 999) === 1) {
		return; // admins have implicit access to all accounts
	}
	global $dbh;
	$companyId = (int) ($_SESSION['user']['company_id'] ?? $_SESSION['user']['companyid'] ?? 0);
	$userId    = (int) ($_SESSION['user']['loggedin'] ?? 0);
	$stmt = $dbh->prepare(
		'SELECT 1 FROM users_accounts
		  WHERE company_id = ?
		    AND user_id = ?
		    AND account_id = ?'
	);
	$stmt->execute([$companyId, $userId, $accountId]);
	if (!$stmt->fetchColumn()) {
		header('Location: ' . u('oops', 'permissions'));
		exit;
	}
}

function getLink() {
	$s = empty($_SERVER["HTTPS"]) ? '' : (($_SERVER["HTTPS"] == "on") ? "s" : "");
	$protocol = substr(strtolower($_SERVER["SERVER_PROTOCOL"]), 0, strpos(strtolower($_SERVER["SERVER_PROTOCOL"]), "/")) . $s;
	$port = ($_SERVER["SERVER_PORT"] == "80") ? "" : (":".$_SERVER["SERVER_PORT"]);
	return $protocol . "://" . $_SERVER['SERVER_NAME'] . $port . $_SERVER['REQUEST_URI'];
}

function sanitize($input,$type = "old") {

	switch ($type) {
	case "int":
		$input = filter_var($input, FILTER_SANITIZE_NUMBER_INT);
	break;

	case "string":
		// FILTER_SANITIZE_STRING was deprecated in PHP 8.1 and removed in PHP 8.2.
		$input = htmlspecialchars(strip_tags((string)$input), ENT_QUOTES, 'UTF-8');
	break;

	case "url": 
		$input = filter_var($input, FILTER_SANITIZE_URL);
	break;

	case "email":
		$input = strtolower(filter_var($input, FILTER_SANITIZE_EMAIL));
	break;

	case "comment":
		$input = htmlentities($input, ENT_QUOTES);
	break;

	case "old":
		echo "Old version of sanitize called";
		exit();
	break;

	}

	return $input;
}


function db() {
	global $dbh;
	try {
	  $dbh = new PDO("mysql:host=".SERVERNAME.";dbname=".DBNAME, DBUSERNAME, DBPASSWORD);
	  $dbh->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
	  load_admin_settings($dbh);
	}
	catch(PDOException $e) {
		echo "We are experiencing very heavy load at the moment. Please try again in 10 minutes.";
		file_put_contents('PDOErrors.txt', $e->getMessage(), FILE_APPEND);
		exit;
	}
}

/**
 * Loads all rows from admin_settings and defines them as PHP constants.
 * Called from db() immediately after the PDO connection is established.
 * Constants already defined (e.g. in tests/bootstrap.php) are never overwritten.
 */
function load_admin_settings(PDO $dbh): void {
	static $keyMap = [
		'owner_email'                   => 'OWNER_EMAIL',
		'recycle_threshold_default'     => 'RECYCLE_THRESHOLD_DEFAULT',
		'recycle_lookahead_days'        => 'RECYCLE_LOOKAHEAD_DAYS',
		'schedule_min_posts'            => 'SCHEDULE_MIN_POSTS',
		'twitter_apikey'                => 'TWITTER_APIKEY',
		'twitter_apisecret'             => 'TWITTER_APISECRET',
		'meta_app_id'                   => 'META_APP_ID',
		'meta_app_secret'               => 'META_APP_SECRET',
		'postmarkapp_api_key'           => 'POSTMARKAPP_API_KEY',
		'postmarkapp_mail_from_address' => 'POSTMARKAPP_MAIL_FROM_ADDRESS',
		'postmarkapp_mail_from_name'    => 'POSTMARKAPP_MAIL_FROM_NAME',
	];

	try {
		$stmt = $dbh->query('SELECT setting_key, setting_val FROM admin_settings');
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
		$map  = array_column($rows, 'setting_val', 'setting_key');

		foreach ($keyMap as $dbKey => $phpConst) {
			if (!defined($phpConst)) {
				$val = $map[$dbKey] ?? '';
				// Numeric settings are cast so arithmetic works as expected
				if (in_array($phpConst, ['RECYCLE_THRESHOLD_DEFAULT', 'RECYCLE_LOOKAHEAD_DAYS', 'SCHEDULE_MIN_POSTS'], true)) {
					define($phpConst, (int) $val);
				} else {
					define($phpConst, (string) $val);
				}
			}
		}
	} catch (Throwable) {
		// admin_settings table may not exist yet (migration 026 not run).
		// Define constants as empty/default values so the app remains functional.
		foreach ($keyMap as $phpConst) {
			if (!defined($phpConst)) {
				define($phpConst, '');
			}
		}
	}
}

function datify($date) {
	return date('g:iA M dS', strtotime($date));
}

function hashPassword(string $password): string {
	return password_hash($password, PASSWORD_BCRYPT);
}

function verifyPassword(string $password, string $hash): bool {
	return password_verify($password, $hash);
}


function sendemail($fromname,$fromaddress,$toemail,$subject,$body,$tag = null) {

	Mail_Postmark::compose()->from($fromaddress,$fromname)
      ->to($toemail)
      ->subject($subject)
      ->messagePlain($body)
	    ->tag($tag)
      ->send();

}

function getExtensionToMimeTypeMapping() {
    return array(
        'ai'=>'application/postscript',
        'aif'=>'audio/x-aiff',
        'aifc'=>'audio/x-aiff',
        'aiff'=>'audio/x-aiff',
        'anx'=>'application/annodex',
        'asc'=>'text/plain',
        'au'=>'audio/basic',
        'avi'=>'video/x-msvideo',
        'axa'=>'audio/annodex',
        'axv'=>'video/annodex',
        'bcpio'=>'application/x-bcpio',
        'bin'=>'application/octet-stream',
        'bmp'=>'image/bmp',
        'c'=>'text/plain',
        'cc'=>'text/plain',
        'ccad'=>'application/clariscad',
        'cdf'=>'application/x-netcdf',
        'class'=>'application/octet-stream',
        'cpio'=>'application/x-cpio',
        'cpt'=>'application/mac-compactpro',
        'csh'=>'application/x-csh',
        'css'=>'text/css',
        'csv'=>'text/csv',
        'dcr'=>'application/x-director',
        'dir'=>'application/x-director',
        'dms'=>'application/octet-stream',
        'doc'=>'application/msword',
        'drw'=>'application/drafting',
        'dvi'=>'application/x-dvi',
        'dwg'=>'application/acad',
        'dxf'=>'application/dxf',
        'dxr'=>'application/x-director',
        'eps'=>'application/postscript',
        'etx'=>'text/x-setext',
        'exe'=>'application/octet-stream',
        'ez'=>'application/andrew-inset',
        'f'=>'text/plain',
        'f90'=>'text/plain',
        'flac'=>'audio/flac',
        'fli'=>'video/x-fli',
        'flv'=>'video/x-flv',
        'gif'=>'image/gif',
        'gtar'=>'application/x-gtar',
        'gz'=>'application/x-gzip',
        'h'=>'text/plain',
        'hdf'=>'application/x-hdf',
        'hh'=>'text/plain',
        'hqx'=>'application/mac-binhex40',
        'htm'=>'text/html',
        'html'=>'text/html',
        'ice'=>'x-conference/x-cooltalk',
        'ief'=>'image/ief',
        'iges'=>'model/iges',
        'igs'=>'model/iges',
        'ips'=>'application/x-ipscript',
        'ipx'=>'application/x-ipix',
        'jpg'=>'image/jpeg',
        'jpeg'=>'image/jpeg',
        'jpg'=>'image/jpeg',
        'js'=>'application/x-javascript',
        'kar'=>'audio/midi',
        'latex'=>'application/x-latex',
        'lha'=>'application/octet-stream',
        'lsp'=>'application/x-lisp',
        'lzh'=>'application/octet-stream',
        'm'=>'text/plain',
        'man'=>'application/x-troff-man',
        'me'=>'application/x-troff-me',
        'mesh'=>'model/mesh',
        'mid'=>'audio/midi',
        'midi'=>'audio/midi',
        'mif'=>'application/vnd.mif',
        'mime'=>'www/mime',
        'mov'=>'video/quicktime',
        'movie'=>'video/x-sgi-movie',
        'mp2'=>'audio/mpeg',
        'mp3'=>'audio/mpeg',
        'mpe'=>'video/mpeg',
        'mpeg'=>'video/mpeg',
        'mpg'=>'video/mpeg',
        'mpga'=>'audio/mpeg',
        'ms'=>'application/x-troff-ms',
        'msh'=>'model/mesh',
        'nc'=>'application/x-netcdf',
        'oga'=>'audio/ogg',
        'ogg'=>'audio/ogg',
        'ogv'=>'video/ogg',
        'ogx'=>'application/ogg',
        'oda'=>'application/oda',
        'pbm'=>'image/x-portable-bitmap',
        'pdb'=>'chemical/x-pdb',
        'pdf'=>'application/pdf',
        'pgm'=>'image/x-portable-graymap',
        'pgn'=>'application/x-chess-pgn',
        'png'=>'image/png',
        'pnm'=>'image/x-portable-anymap',
        'pot'=>'application/mspowerpoint',
        'ppm'=>'image/x-portable-pixmap',
        'pps'=>'application/mspowerpoint',
        'ppt'=>'application/mspowerpoint',
        'ppz'=>'application/mspowerpoint',
        'pre'=>'application/x-freelance',
        'prt'=>'application/pro_eng',
        'ps'=>'application/postscript',
        'qt'=>'video/quicktime',
        'ra'=>'audio/x-realaudio',
        'ram'=>'audio/x-pn-realaudio',
        'ras'=>'image/cmu-raster',
        'rgb'=>'image/x-rgb',
        'rm'=>'audio/x-pn-realaudio',
        'roff'=>'application/x-troff',
        'rpm'=>'audio/x-pn-realaudio-plugin',
        'rtf'=>'text/rtf',
        'rtx'=>'text/richtext',
        'scm'=>'application/x-lotusscreencam',
        'set'=>'application/set',
        'sgm'=>'text/sgml',
        'sgml'=>'text/sgml',
        'sh'=>'application/x-sh',
        'shar'=>'application/x-shar',
        'silo'=>'model/mesh',
        'sit'=>'application/x-stuffit',
        'skd'=>'application/x-koan',
        'skm'=>'application/x-koan',
        'skp'=>'application/x-koan',
        'skt'=>'application/x-koan',
        'smi'=>'application/smil',
        'smil'=>'application/smil',
        'snd'=>'audio/basic',
        'sol'=>'application/solids',
        'spl'=>'application/x-futuresplash',
        'spx'=>'audio/ogg',
        'src'=>'application/x-wais-source',
        'step'=>'application/STEP',
        'stl'=>'application/SLA',
        'stp'=>'application/STEP',
        'sv4cpio'=>'application/x-sv4cpio',
        'sv4crc'=>'application/x-sv4crc',
        'swf'=>'application/x-shockwave-flash',
        't'=>'application/x-troff',
        'tar'=>'application/x-tar',
        'tcl'=>'application/x-tcl',
        'tex'=>'application/x-tex',
        'texi'=>'application/x-texinfo',
        'texinfo'=>'application/x-texinfo',
        'tif'=>'image/tiff',
        'tiff'=>'image/tiff',
        'tr'=>'application/x-troff',
        'tsi'=>'audio/TSP-audio',
        'tsp'=>'application/dsptype',
        'tsv'=>'text/tab-separated-values',
        'txt'=>'text/plain',
        'unv'=>'application/i-deas',
        'ustar'=>'application/x-ustar',
        'vcd'=>'application/x-cdlink',
        'vda'=>'application/vda',
        'viv'=>'video/vnd.vivo',
        'vivo'=>'video/vnd.vivo',
        'vrml'=>'model/vrml',
        'wav'=>'audio/x-wav',
        'wrl'=>'model/vrml',
        'xbm'=>'image/x-xbitmap',
        'xlc'=>'application/vnd.ms-excel',
        'xll'=>'application/vnd.ms-excel',
        'xlm'=>'application/vnd.ms-excel',
        'xls'=>'application/vnd.ms-excel',
        'xlw'=>'application/vnd.ms-excel',
        'xml'=>'application/xml',
        'xpm'=>'image/x-xpixmap',
        'xspf'=>'application/xspf+xml',
        'xwd'=>'image/x-xwindowdump',
        'xyz'=>'chemical/x-pdb',
        'zip'=>'application/zip',
    );
}

function getMimeType($filePath) {

    if (!is_file($filePath)) {
        return false;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $filePath);
    finfo_close($finfo);

    return $mime;
}

function upload($filePath, $destinationDir = 'images', array $allowedMimes = array()) {

    if (!is_file($filePath) || !is_dir($destinationDir)) {
        return false;
    }

    if (!($mime = getMimeType($filePath))) {
        return false;
    }

    if (!in_array($mime, $allowedMimes)) {
        return false;
    }

    $ext = null;
    $extMapping = getExtensionToMimeTypeMapping();
    foreach ($extMapping as $extension => $mimeType) {
        if ($mimeType == $mime) {
            $ext = $extension;
            break;
        }
    }

    if (empty($ext)) {
        $ext = pathinfo($filePath, PATHINFO_EXTENSION);
    }

    if (empty($ext)) {
        return false;
    }

    $fileName = bin2hex(random_bytes(16)) . '.' . $ext;
    $newFilePath = $destinationDir.'/'.$fileName;

    if(!rename($filePath, $newFilePath)) {
        return false;
    }

    return $fileName;
}

/**
 * Produces a normalized fingerprint of a post body for duplicate detection.
 * The result is stored in posts.body_normalized — never displayed to users.
 *
 * Algorithm (in order):
 *   1. Lowercase
 *   2. Strip URLs (http/https)
 *   3. Strip all punctuation and symbols — keep only letters, numbers, whitespace
 *   4. Collapse all whitespace to a single space
 *   5. Trim
 *   6. Truncate to 280 characters
 */
function normalize_body(string $body): string
{
    $n = mb_strtolower($body);
    $n = (string) preg_replace('~https?://\S+~', '', $n);
    $n = (string) preg_replace('/[^\p{L}\p{N}\s]/u', '', $n);
    $n = (string) preg_replace('/\s+/', ' ', $n);
    $n = trim($n);
    return mb_substr($n, 0, 280);
}

/**
 * Assembles the final post body for queue insertion.
 * Appends attribution (if present) then hashtags up to the platform limit.
 * Single source of truth for final_body assembly across all code paths.
 */
function build_final_body(string $body, ?string $attributedTo, ?string $defaultTagsJson, string $platform): string
{
    $assembled = $body;
    if (!empty($attributedTo)) {
        $assembled .= ' - ' . $attributedTo;
    }
    $appender = new SocialTurn\Services\TagAppenderService();
    $result   = $appender->append($assembled, $defaultTagsJson, $platform);
    return $result['body'];
}

if (!defined('RUNNING_TESTS')) {
    db();
    authenticate();
}