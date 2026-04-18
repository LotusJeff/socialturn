<?php
/**
 * SocialTurn Installation Wizard
 *
 * Run once on a fresh install to configure the database connection,
 * create the schema, and set up the first admin account.
 *
 * On success, writes:
 *   - socialturn.ini  at the user-chosen absolute path
 *   - boot.php        in this directory (defines CONFIG_PATH)
 *
 * After installation is complete, DELETE THIS FILE.
 * index.php will display a security warning on every page for admin
 * users until install.php is removed.
 */

date_default_timezone_set('UTC');
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', dirname(__FILE__) . '/error.log');

define('ROOT',        dirname(__FILE__));
define('DS',          DIRECTORY_SEPARATOR);
define('SCHEMA_PATH', ROOT . DS . 'db' . DS . 'schema.sql');
define('MIG_026',     ROOT . DS . 'db' . DS . 'migrations' . DS . '026_admin_settings.sql');

// ============================================================
// Refuse to run if already installed (boot.php exists)
// ============================================================
if (file_exists(ROOT . DS . 'boot.php')) {
    ?>
    <!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Already installed</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css"></head>
    <body class="bg-light"><div class="container py-5" style="max-width:540px">
    <div class="alert alert-warning"><strong>Already installed.</strong>
    boot.php already exists. SocialTurn is configured.<br>
    Delete <code>install.php</code> to remove this message.</div>
    </div></body></html>
    <?php
    exit;
}

// ============================================================
// Helpers
// ============================================================

function iniEsc(string $val): string {
    return '"' . str_replace(['"', '\\'], ['\\"', '\\\\'], $val) . '"';
}

function h(string $val): string {
    return htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
}

function runSqlFile(PDO $pdo, string $file): void {
    $sql = (string) file_get_contents($file);
    $sql = (string) preg_replace('/--[^\n]*\n/', "\n", $sql);
    $sql = (string) preg_replace('/\/\*.*?\*\//s', '', $sql);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if ($stmt !== '') {
            $pdo->exec($stmt);
        }
    }
}

// ============================================================
// Auto-detect defaults
// ============================================================

// Base URL
$proto       = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host        = $_SERVER['HTTP_HOST'] ?? 'localhost';
$dir         = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\') . '/';
$defaultBase = $proto . '://' . $host . $dir;

// socialturn.ini path — one level above DOCUMENT_ROOT, fallback if too shallow
$docRoot       = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
$parentDir     = $docRoot !== '' ? dirname($docRoot) : '';
$parentSegments = array_filter(explode('/', $parentDir));
if ($parentDir !== '' && count($parentSegments) >= 3) {
    $defaultIniPath = $parentDir . '/socialturn.ini';
} else {
    $defaultIniPath = '/var/www/socialturn.ini';
}

// ============================================================
// POST — process installation
// ============================================================
$errors      = [];
$installed   = false;
$iniPathWarn = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // --- collect inputs ---
    $iniPath     = trim((string) ($_POST['ini_path']  ?? ''));
    $dbHost      = trim((string) ($_POST['db_host']   ?? ''));
    $dbName      = trim((string) ($_POST['db_name']   ?? ''));
    $dbUser      = trim((string) ($_POST['db_user']   ?? ''));
    $dbPass      = (string) ($_POST['db_pass']         ?? '');
    $baseUrl     = rtrim(trim((string) ($_POST['base_url'] ?? '')), '/') . '/';
    $orgName     = trim((string) ($_POST['org_name']  ?? '')) ?: 'SocialTurn';
    $adminEmail  = trim(strtolower((string) ($_POST['admin_email']            ?? '')));
    $adminPass   = (string) ($_POST['admin_password']          ?? '');
    $adminPass2  = (string) ($_POST['admin_password_confirm']  ?? '');
    $pmKey       = trim((string) ($_POST['postmarkapp_api_key']           ?? ''));
    $pmFrom      = trim((string) ($_POST['postmarkapp_mail_from_address'] ?? ''));
    $pmName      = trim((string) ($_POST['postmarkapp_mail_from_name']   ?? ''));
    $twKey       = trim((string) ($_POST['twitter_apikey']    ?? ''));
    $twSecret    = trim((string) ($_POST['twitter_apisecret'] ?? ''));
    $metaId      = trim((string) ($_POST['meta_app_id']       ?? ''));
    $metaSecret  = trim((string) ($_POST['meta_app_secret']   ?? ''));
    $threshold   = max(1, (int) ($_POST['recycle_threshold_default'] ?? 10));
    $lookahead   = max(1, (int) ($_POST['recycle_lookahead_days']    ?? 30));
    $minPosts    = max(1, (int) ($_POST['schedule_min_posts']        ?? 5));

    // --- validate ini path ---
    if ($iniPath === '') {
        $errors[] = 'Configuration file path is required.';
    } else {
        $iniDir = dirname($iniPath);
        if (!is_dir($iniDir)) {
            $errors[] = "Configuration file directory does not exist: {$iniDir}";
        } elseif (!is_writable($iniDir)) {
            $errors[] = "Configuration file directory is not writable by PHP: {$iniDir} — check permissions.";
        } else {
            // Advisory: warn if path is inside DOCUMENT_ROOT
            $docRootReal = realpath($docRoot) ?: $docRoot;
            $iniDirReal  = realpath($iniDir)  ?: $iniDir;
            if ($docRootReal !== '' && str_starts_with(
                rtrim($iniDirReal, '/') . '/',
                rtrim($docRootReal, '/') . '/'
            )) {
                $iniPathWarn = true;
            }
        }
    }

    // --- validate remaining required fields ---
    if ($dbHost === '')    $errors[] = 'Database host is required.';
    if ($dbName === '')    $errors[] = 'Database name is required.';
    if ($dbUser === '')    $errors[] = 'Database user is required.';
    if ($baseUrl === '/')  $errors[] = 'Site base URL is required.';
    if ($adminEmail === '') $errors[] = 'Admin email is required.';
    if ($adminEmail !== '' && !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Admin email is not a valid email address.';
    }
    if (strlen($adminPass) < 12)                   $errors[] = 'Password must be at least 12 characters.';
    if (!preg_match('/[A-Z]/', $adminPass))         $errors[] = 'Password must contain an uppercase letter.';
    if (!preg_match('/[0-9]/', $adminPass))         $errors[] = 'Password must contain a number.';
    if (!preg_match('/[^a-zA-Z0-9]/', $adminPass)) $errors[] = 'Password must contain a special character.';
    if ($adminPass !== $adminPass2)                 $errors[] = 'Passwords do not match.';

    // --- test DB connection ---
    if (empty($errors)) {
        try {
            $pdo = new PDO(
                "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
                $dbUser,
                $dbPass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
            );
        } catch (PDOException $e) {
            $errors[] = 'Database connection failed: ' . $e->getMessage();
        }
    }

    // --- check for existing data ---
    if (empty($errors)) {
        try {
            $existing = (int) $pdo->query('SELECT COUNT(*) FROM companies')->fetchColumn();
            if ($existing > 0) {
                $errors[] = 'This database already contains SocialTurn data. '
                          . 'The install wizard is for fresh installs only. '
                          . 'To configure an existing install, create socialturn.ini and '
                          . 'boot.php manually, then run migration 026_admin_settings.sql.';
            }
        } catch (PDOException) {
            // companies table does not exist yet — expected on a fresh database
        }
    }

    // --- run install ---
    if (empty($errors)) {
        try {
            // Load schema
            runSqlFile($pdo, SCHEMA_PATH);

            // Load migration 026 (admin_settings)
            runSqlFile($pdo, MIG_026);

            $pdo->beginTransaction();

            // Create company
            $pdo->prepare('INSERT INTO companies (name, active) VALUES (?, 1)')
                ->execute([$orgName]);
            $companyId = (int) $pdo->lastInsertId();

            // Create admin user
            $hash = password_hash($adminPass, PASSWORD_BCRYPT);
            $pdo->prepare(
                'INSERT INTO users (company_id, email, password, type, active) VALUES (?, ?, ?, 1, 1)'
            )->execute([$companyId, $adminEmail, $hash]);

            // Populate admin_settings
            $adminSettings = [
                'owner_email'                   => $adminEmail,
                'recycle_threshold_default'     => (string) $threshold,
                'recycle_lookahead_days'        => (string) $lookahead,
                'schedule_min_posts'            => (string) $minPosts,
                'twitter_apikey'                => $twKey,
                'twitter_apisecret'             => $twSecret,
                'meta_app_id'                   => $metaId,
                'meta_app_secret'               => $metaSecret,
                'postmarkapp_api_key'           => $pmKey,
                'postmarkapp_mail_from_address' => $pmFrom,
                'postmarkapp_mail_from_name'    => $pmName,
            ];
            $stmt = $pdo->prepare(
                'UPDATE admin_settings SET setting_val = ? WHERE setting_key = ?'
            );
            foreach ($adminSettings as $k => $v) {
                $stmt->execute([$v, $k]);
            }

            $pdo->commit();

            // Write socialturn.ini to the user-chosen absolute path
            $iniContent = "[socialturn]\n"
                        . 'db_host = '  . iniEsc($dbHost)   . "\n"
                        . 'db_name = '  . iniEsc($dbName)   . "\n"
                        . 'db_user = '  . iniEsc($dbUser)   . "\n"
                        . 'db_pass = '  . iniEsc($dbPass)   . "\n"
                        . 'base_url = ' . iniEsc($baseUrl)  . "\n";

            if (file_put_contents($iniPath, $iniContent) === false) {
                throw new RuntimeException(
                    'Could not write socialturn.ini to: ' . $iniPath . '. '
                    . 'Ensure the web server user has write permission to: ' . dirname($iniPath)
                );
            }
            @chmod($iniPath, 0644);

            // Write boot.php to the web root — defines CONFIG_PATH
            $bootContent = '<?php define(\'CONFIG_PATH\', ' . var_export($iniPath, true) . ');' . "\n";
            $bootPath    = ROOT . DS . 'boot.php';

            if (file_put_contents($bootPath, $bootContent) === false) {
                throw new RuntimeException(
                    'Could not write boot.php to: ' . $bootPath . '. '
                    . 'Ensure the web server user has write permission to: ' . ROOT
                );
            }
            @chmod($bootPath, 0644);

            $installed = true;

        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Installation failed: ' . $e->getMessage();
        }
    }
}

// ============================================================
// Form repopulation values (never repopulate passwords)
// ============================================================
$rawIniPath = trim((string) ($_POST['ini_path'] ?? $defaultIniPath));

$f = [
    'ini_path'    => h($rawIniPath),
    'db_host'     => h((string) ($_POST['db_host']    ?? 'localhost')),
    'db_name'     => h((string) ($_POST['db_name']    ?? '')),
    'db_user'     => h((string) ($_POST['db_user']    ?? '')),
    'base_url'    => h((string) ($_POST['base_url']   ?? $defaultBase)),
    'org_name'    => h((string) ($_POST['org_name']   ?? 'SocialTurn')),
    'admin_email' => h((string) ($_POST['admin_email'] ?? '')),
    'pm_key'      => h((string) ($_POST['postmarkapp_api_key']           ?? '')),
    'pm_from'     => h((string) ($_POST['postmarkapp_mail_from_address'] ?? '')),
    'pm_name'     => h((string) ($_POST['postmarkapp_mail_from_name']   ?? '')),
    'tw_key'      => h((string) ($_POST['twitter_apikey']    ?? '')),
    'tw_secret'   => h((string) ($_POST['twitter_apisecret'] ?? '')),
    'meta_id'     => h((string) ($_POST['meta_app_id']       ?? '')),
    'meta_secret' => h((string) ($_POST['meta_app_secret']   ?? '')),
    'threshold'   => h((string) ($_POST['recycle_threshold_default'] ?? '10')),
    'lookahead'   => h((string) ($_POST['recycle_lookahead_days']    ?? '30')),
    'min_posts'   => h((string) ($_POST['schedule_min_posts']        ?? '5')),
];
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $f['base_url'] = h($defaultBase);
}

// Compute advisory warning for the ini path field (GET and POST)
if (!$iniPathWarn) {
    $docRootReal = realpath($docRoot) ?: $docRoot;
    $iniDirReal  = realpath(dirname($rawIniPath)) ?: dirname($rawIniPath);
    if ($docRootReal !== '' && str_starts_with(
        rtrim($iniDirReal, '/') . '/',
        rtrim($docRootReal, '/') . '/'
    )) {
        $iniPathWarn = true;
    }
}

$loginUrl = rtrim((string) ($_POST['base_url'] ?? ''), '/') . '/index.php?c=users&a=login';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SocialTurn &mdash; Install</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
</head>
<body class="bg-light">

<div class="container py-5" style="max-width:680px">

    <div class="text-center mb-4">
        <img src="assets/img/logo.png" alt="SocialTurn" height="40" class="mb-2">
        <h1 class="h3 fw-bold mb-0">SocialTurn</h1>
        <p class="text-muted">Installation Wizard</p>
    </div>

<?php if ($installed): ?>

    <!-- ============================================================
         Success screen
         ============================================================ -->

    <div class="alert alert-danger mb-4" role="alert">
        <h5 class="alert-heading fw-bold">
            &#9888; Delete install.php before using the app
        </h5>
        <p>
            Installation is complete. <strong>You must delete
            <code>install.php</code> from your server right now.</strong>
            Leaving this file in place is a security risk &mdash; anyone who
            can reach your server can run it and overwrite your configuration.
        </p>
        <p class="mb-1 fw-semibold">Run this command on your server:</p>
        <pre class="mb-0 bg-dark text-light rounded p-2">rm <?php echo h(ROOT . DS . 'install.php'); ?></pre>
    </div>

    <div class="card shadow-sm">
        <div class="card-body p-4 text-center">
            <div class="mb-3 text-success">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
                    <path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0m-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z"/>
                </svg>
            </div>
            <h4 class="fw-semibold mb-1">Installation complete</h4>
            <p class="text-muted mb-1">
                Database configured, schema loaded, and admin account created.
            </p>
            <p class="text-muted small mb-4">
                Configuration written to: <code><?php echo $f['ini_path']; ?></code>
            </p>
            <p class="text-muted small mb-4">Delete <code>install.php</code> before logging in.</p>
            <a href="<?php echo h($loginUrl); ?>" class="btn btn-primary">Go to Dashboard &rarr;</a>
        </div>
    </div>

<?php else: ?>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger mb-4">
        <strong>Please fix the following before proceeding:</strong>
        <ul class="mb-0 mt-2">
            <?php foreach ($errors as $err): ?>
            <li><?php echo h($err); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- ============================================================
         Multi-step form — Alpine.js controls visible step.
         All fields are in the DOM so they all submit on the final step.
         ============================================================ -->
    <div x-data="{
        step: 1,
        pw: '',
        pw2: '',
        get pwMinLen()  { return this.pw.length >= 12 },
        get pwUpper()   { return /[A-Z]/.test(this.pw) },
        get pwNumber()  { return /[0-9]/.test(this.pw) },
        get pwSpecial() { return /[^a-zA-Z0-9]/.test(this.pw) },
        get pwMatch()   { return this.pw !== '' && this.pw === this.pw2 },
        get pwOk()      { return this.pwMinLen && this.pwUpper && this.pwNumber && this.pwSpecial && this.pwMatch }
    }">

        <!-- Step indicator -->
        <div class="d-flex align-items-center justify-content-center gap-1 mb-4">
            <template x-for="n in [1,2,3,4]" :key="n">
                <div class="d-flex align-items-center">
                    <div class="rounded-circle d-flex align-items-center justify-content-center fw-semibold small"
                         :class="{
                             'bg-primary text-white': step === n,
                             'bg-success text-white': step > n,
                             'bg-secondary text-white': step < n
                         }"
                         style="width:2rem;height:2rem"
                         x-text="n"></div>
                    <div x-show="n < 4" style="width:2.5rem;height:2px;background:#dee2e6" class="mx-1"></div>
                </div>
            </template>
        </div>

        <form method="post" action="">

        <!-- ========================================================
             Step 1 — Database & Site URL
             ======================================================== -->
        <div x-show="step === 1" x-cloak>
            <div class="card shadow-sm mb-3">
                <div class="card-header fw-semibold">
                    Step 1 of 4 &mdash; Database &amp; Site URL
                </div>
                <div class="card-body p-4">

                    <div class="mb-4">
                        <label class="form-label" for="ini_path">Configuration file path</label>
                        <input type="text" class="form-control font-monospace" id="ini_path" name="ini_path"
                               value="<?php echo $f['ini_path']; ?>" required>
                        <div class="form-text">
                            Absolute server path where <code>socialturn.ini</code> will be written.
                            For maximum security, choose a path above your web root.
                        </div>
                        <?php if ($iniPathWarn): ?>
                        <div class="alert alert-warning py-2 px-3 small mt-2 mb-0">
                            This path is inside your web root. For maximum security, choose a path
                            above the document root. You can continue, but ensure your web server
                            denies access to <code>.ini</code> files (the included
                            <code>.htaccess</code> and <code>nginx.conf.sample</code> rules handle this).
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="db_host">Database host</label>
                        <input type="text" class="form-control" id="db_host" name="db_host"
                               value="<?php echo $f['db_host']; ?>" required>
                        <div class="form-text">Usually <code>localhost</code></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="db_name">Database name</label>
                        <input type="text" class="form-control" id="db_name" name="db_name"
                               value="<?php echo $f['db_name']; ?>" required>
                        <div class="form-text">The database must already exist. Create it with: <code>CREATE DATABASE socialturn CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;</code></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="db_user">Database user</label>
                        <input type="text" class="form-control" id="db_user" name="db_user"
                               value="<?php echo $f['db_user']; ?>" required autocomplete="username">
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="db_pass">Database password</label>
                        <input type="password" class="form-control" id="db_pass" name="db_pass"
                               autocomplete="current-password">
                    </div>

                    <div class="mb-0">
                        <label class="form-label" for="base_url">Site base URL</label>
                        <input type="url" class="form-control" id="base_url" name="base_url"
                               value="<?php echo $f['base_url']; ?>" required>
                        <div class="form-text">
                            Full public URL with trailing slash &mdash;
                            e.g. <code>https://example.com/socialturn/</code>
                        </div>
                    </div>

                </div>
            </div>
            <div class="d-flex justify-content-end">
                <button type="button" class="btn btn-primary" @click="step = 2">
                    Next &rarr;
                </button>
            </div>
        </div>

        <!-- ========================================================
             Step 2 — Admin Account
             ======================================================== -->
        <div x-show="step === 2" x-cloak>
            <div class="card shadow-sm mb-3">
                <div class="card-header fw-semibold">
                    Step 2 of 4 &mdash; Admin Account
                </div>
                <div class="card-body p-4">

                    <div class="mb-3">
                        <label class="form-label" for="org_name">Organization name</label>
                        <input type="text" class="form-control" id="org_name" name="org_name"
                               value="<?php echo $f['org_name']; ?>">
                        <div class="form-text">Your company or team name. You can change it later.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="admin_email">Admin email address</label>
                        <input type="email" class="form-control" id="admin_email" name="admin_email"
                               value="<?php echo $f['admin_email']; ?>" required autocomplete="email">
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="admin_password">Password</label>
                        <input type="password" class="form-control" id="admin_password"
                               name="admin_password" x-model="pw" autocomplete="new-password">
                        <ul class="list-unstyled mt-2 mb-0 small">
                            <li :class="pwMinLen  ? 'text-success' : 'text-muted'">&#10003; At least 12 characters</li>
                            <li :class="pwUpper   ? 'text-success' : 'text-muted'">&#10003; Uppercase letter</li>
                            <li :class="pwNumber  ? 'text-success' : 'text-muted'">&#10003; Number</li>
                            <li :class="pwSpecial ? 'text-success' : 'text-muted'">&#10003; Special character</li>
                        </ul>
                    </div>

                    <div class="mb-0">
                        <label class="form-label" for="admin_password_confirm">Confirm password</label>
                        <input type="password" class="form-control" id="admin_password_confirm"
                               name="admin_password_confirm" x-model="pw2" autocomplete="new-password">
                        <div class="form-text"
                             :class="pw2 !== '' ? (pwMatch ? 'text-success' : 'text-danger') : ''">
                            <span x-show="pw2 === ''">Re-enter your password.</span>
                            <span x-show="pw2 !== '' && pwMatch">Passwords match.</span>
                            <span x-show="pw2 !== '' && !pwMatch">Passwords do not match.</span>
                        </div>
                    </div>

                </div>
            </div>
            <div class="d-flex justify-content-between">
                <button type="button" class="btn btn-outline-secondary" @click="step = 1">
                    &larr; Back
                </button>
                <button type="button" class="btn btn-primary" @click="step = 3" :disabled="!pwOk">
                    Next &rarr;
                </button>
            </div>
        </div>

        <!-- ========================================================
             Step 3 — Email (Postmark)
             ======================================================== -->
        <div x-show="step === 3" x-cloak>
            <div class="card shadow-sm mb-3">
                <div class="card-header fw-semibold">
                    Step 3 of 4 &mdash; Email <span class="text-muted fw-normal">(optional)</span>
                </div>
                <div class="card-body p-4">

                    <p class="text-muted small mb-4">
                        Required for password resets and team invites.
                        Sign up for a free account at
                        <strong>postmarkapp.com</strong> (100 emails/month free).
                        You can skip this step and add credentials later in
                        Settings &rarr; Email.
                    </p>

                    <div class="mb-3">
                        <label class="form-label" for="postmarkapp_api_key">Server API Token</label>
                        <input type="text" class="form-control font-monospace"
                               id="postmarkapp_api_key" name="postmarkapp_api_key"
                               value="<?php echo $f['pm_key']; ?>"
                               placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
                        <div class="form-text">From your Postmark Server&rsquo;s API Tokens tab.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="postmarkapp_mail_from_address">From address</label>
                        <input type="email" class="form-control"
                               id="postmarkapp_mail_from_address"
                               name="postmarkapp_mail_from_address"
                               value="<?php echo $f['pm_from']; ?>"
                               placeholder="noreply@yourdomain.com">
                        <div class="form-text">
                            Must be a verified sender signature in Postmark.
                            Gmail, Yahoo, and other public providers are not permitted.
                        </div>
                    </div>

                    <div class="mb-0">
                        <label class="form-label" for="postmarkapp_mail_from_name">From name</label>
                        <input type="text" class="form-control"
                               id="postmarkapp_mail_from_name" name="postmarkapp_mail_from_name"
                               value="<?php echo $f['pm_name']; ?>"
                               placeholder="SocialTurn">
                    </div>

                </div>
            </div>
            <div class="d-flex justify-content-between">
                <button type="button" class="btn btn-outline-secondary" @click="step = 2">
                    &larr; Back
                </button>
                <button type="button" class="btn btn-primary" @click="step = 4">
                    Next &rarr;
                </button>
            </div>
        </div>

        <!-- ========================================================
             Step 4 — Platform Credentials + Install
             ======================================================== -->
        <div x-show="step === 4" x-cloak>
            <div class="card shadow-sm mb-3">
                <div class="card-header fw-semibold">
                    Step 4 of 4 &mdash; Platform Credentials <span class="text-muted fw-normal">(optional)</span>
                </div>
                <div class="card-body p-4">

                    <p class="text-muted small mb-4">
                        You can skip this step and add credentials later in
                        Settings &rarr; Platform Credentials.
                    </p>

                    <h6 class="fw-semibold">Twitter / X</h6>
                    <p class="text-muted small mb-3">
                        Create a project and app at <strong>developer.twitter.com</strong>.
                        Set permissions to <strong>Read and Write</strong>.
                        Copy the Consumer Key and Consumer Secret from the app&rsquo;s
                        Keys and Tokens page.
                    </p>

                    <div class="mb-3">
                        <label class="form-label" for="twitter_apikey">Consumer Key (API Key)</label>
                        <input type="text" class="form-control font-monospace"
                               id="twitter_apikey" name="twitter_apikey"
                               value="<?php echo $f['tw_key']; ?>">
                    </div>

                    <div class="mb-4">
                        <label class="form-label" for="twitter_apisecret">Consumer Secret (API Secret)</label>
                        <input type="text" class="form-control font-monospace"
                               id="twitter_apisecret" name="twitter_apisecret"
                               value="<?php echo $f['tw_secret']; ?>">
                    </div>

                    <h6 class="fw-semibold">Facebook / Instagram</h6>
                    <p class="text-muted small mb-3">
                        Create an app at <strong>developers.facebook.com</strong>.
                        Add the <strong>Facebook Login</strong> and
                        <strong>Instagram Graph API</strong> products.
                        Copy the App ID and App Secret from App Settings &rarr; Basic.
                        Instagram connects through the same app.
                    </p>

                    <div class="mb-3">
                        <label class="form-label" for="meta_app_id">App ID</label>
                        <input type="text" class="form-control font-monospace"
                               id="meta_app_id" name="meta_app_id"
                               value="<?php echo $f['meta_id']; ?>">
                    </div>

                    <div class="mb-4">
                        <label class="form-label" for="meta_app_secret">App Secret</label>
                        <input type="text" class="form-control font-monospace"
                               id="meta_app_secret" name="meta_app_secret"
                               value="<?php echo $f['meta_secret']; ?>">
                    </div>

                    <!-- Hidden app settings — use defaults, configurable post-install -->
                    <input type="hidden" name="recycle_threshold_default" value="<?php echo $f['threshold']; ?>">
                    <input type="hidden" name="recycle_lookahead_days"    value="<?php echo $f['lookahead']; ?>">
                    <input type="hidden" name="schedule_min_posts"        value="<?php echo $f['min_posts']; ?>">

                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center">
                <button type="button" class="btn btn-outline-secondary" @click="step = 3">
                    &larr; Back
                </button>
                <button type="submit" class="btn btn-success btn-lg px-4">
                    Install SocialTurn
                </button>
            </div>
        </div>

        </form>
    </div>

<?php endif; ?>

</div>

<script src="assets/js/alpine.min.js" defer></script>
</body>
</html>
