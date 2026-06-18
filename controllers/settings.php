<?php

function index(): void {
    global $dbh, $template;
    checkPermission(1);

    $ini = @parse_ini_file(CONFIG_PATH, true);
    $cfg = $ini['socialturn'] ?? [];

    $companyId = (int) ($_SESSION['user']['company_id'] ?? $_SESSION['user']['companyid'] ?? 0);
    $stmt = $dbh->prepare('SELECT COUNT(*) FROM connected_platforms WHERE company_id = ?');
    $stmt->execute([$companyId]);

    $template->set('dbHost',          $cfg['db_host'] ?? '');
    $template->set('dbName',          $cfg['db_name'] ?? '');
    $template->set('baseUrl',         $cfg['base_url'] ?? '');
    $template->set('pmConfigured',    !empty(POSTMARKAPP_API_KEY));
    $template->set('notifyFailure',   defined('NOTIFY_POST_FAILURE') ? NOTIFY_POST_FAILURE : '0');
    $template->set('connectionCount', (int) $stmt->fetchColumn());
}

function database(): void {
    global $dbh, $template;
    checkPermission(1);

    $template->set('saveError',   null);
    $template->set('saveSuccess', false);

    $ini = @parse_ini_file(CONFIG_PATH, true);
    $cfg = $ini['socialturn'] ?? [];

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $template->set('dbHost',  $cfg['db_host']  ?? '');
        $template->set('dbName',  $cfg['db_name']  ?? '');
        $template->set('dbUser',  $cfg['db_user']  ?? '');
        $template->set('baseUrl', $cfg['base_url'] ?? '');
        $template->set('csrfToken', csrf_token());
        return;
    }

    if (!csrf_validate()) {
        header('Location: ' . u('settings', 'database'));
        exit;
    }

    $newHost    = trim((string) ($_POST['db_host']  ?? ''));
    $newName    = trim((string) ($_POST['db_name']  ?? ''));
    $newUser    = trim((string) ($_POST['db_user']  ?? ''));
    $newPass    = (string) ($_POST['db_pass']       ?? '');
    $newBaseUrl = rtrim(trim((string) ($_POST['base_url'] ?? '')), '/') . '/';

    // Keep current password if not re-entered
    $currentPass = $cfg['db_pass'] ?? '';
    $dbPass = ($newPass !== '') ? $newPass : $currentPass;

    $errors = [];
    if ($newHost === '') $errors[] = 'Database host is required.';
    if ($newName === '') $errors[] = 'Database name is required.';
    if ($newUser === '') $errors[] = 'Database user is required.';
    if ($newBaseUrl === '/') $errors[] = 'Base URL is required.';

    if (empty($errors)) {
        try {
            $testPdo = new PDO(
                "mysql:host={$newHost};dbname={$newName};charset=utf8mb4",
                $newUser,
                $dbPass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            unset($testPdo);
        } catch (PDOException $e) {
            $errors[] = 'Connection test failed: ' . $e->getMessage();
        }
    }

    if (!empty($errors)) {
        $template->set('saveError', implode(' ', $errors));
        $template->set('dbHost',  $newHost);
        $template->set('dbName',  $newName);
        $template->set('dbUser',  $newUser);
        $template->set('baseUrl', $newBaseUrl);
        $template->set('csrfToken', csrf_token());
        return;
    }

    $ini = "[socialturn]\n"
         . 'db_host = "' . addcslashes($newHost,    '"\\') . '"' . "\n"
         . 'db_name = "' . addcslashes($newName,    '"\\') . '"' . "\n"
         . 'db_user = "' . addcslashes($newUser,    '"\\') . '"' . "\n"
         . 'db_pass = "' . addcslashes($dbPass,     '"\\') . '"' . "\n"
         . 'base_url = "' . addcslashes($newBaseUrl, '"\\') . '"' . "\n";

    if (file_put_contents(CONFIG_PATH, $ini) === false) {
        $template->set('saveError', 'Could not write socialturn.ini. Check file permissions on ' . CONFIG_PATH);
        $template->set('dbHost',  $newHost);
        $template->set('dbName',  $newName);
        $template->set('dbUser',  $newUser);
        $template->set('baseUrl', $newBaseUrl);
        $template->set('csrfToken', csrf_token());
        return;
    }

    $template->set('saveSuccess', true);
    $template->set('dbHost',  $newHost);
    $template->set('dbName',  $newName);
    $template->set('dbUser',  $newUser);
    $template->set('baseUrl', $newBaseUrl);
    $template->set('csrfToken', csrf_token());
}

function email(): void {
    global $dbh, $template;
    checkPermission(1);

    $template->set('saveError',   null);
    $template->set('saveSuccess', false);
    $template->set('csrfToken', csrf_token());

    $template->set('pmKey',  POSTMARKAPP_API_KEY);
    $template->set('pmFrom', POSTMARKAPP_MAIL_FROM_ADDRESS);
    $template->set('pmName', POSTMARKAPP_MAIL_FROM_NAME);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    if (!csrf_validate()) {
        header('Location: ' . u('settings', 'email'));
        exit;
    }

    $pmKey  = trim((string) ($_POST['postmarkapp_api_key']           ?? ''));
    $pmFrom = trim((string) ($_POST['postmarkapp_mail_from_address'] ?? ''));
    $pmName = trim((string) ($_POST['postmarkapp_mail_from_name']   ?? ''));

    $updates = [
        'postmarkapp_api_key'           => $pmKey,
        'postmarkapp_mail_from_address' => $pmFrom,
        'postmarkapp_mail_from_name'    => $pmName,
    ];
    save_admin_settings($dbh, $updates);

    $template->set('pmKey',      $pmKey);
    $template->set('pmFrom',     $pmFrom);
    $template->set('pmName',     $pmName);
    $template->set('saveSuccess', true);
}


function app(): void {
    global $dbh, $template;
    checkPermission(1);

    $template->set('saveError',   null);
    $template->set('saveSuccess', false);
    $template->set('csrfToken', csrf_token());

    $template->set('ownerEmail',      OWNER_EMAIL);
    $template->set('threshold',       RECYCLE_THRESHOLD_DEFAULT);
    $template->set('lookahead',       RECYCLE_LOOKAHEAD_DAYS);
    $template->set('minPosts',        SCHEDULE_MIN_POSTS);
    $template->set('notifyFailure',   defined('NOTIFY_POST_FAILURE')    ? NOTIFY_POST_FAILURE    : '0');
    $template->set('notifyFrequency', defined('NOTIFY_RECAP_FREQUENCY') ? NOTIFY_RECAP_FREQUENCY : 'weekly');
    $template->set('notifyEmail',     defined('NOTIFY_RECIPIENT_EMAIL') ? NOTIFY_RECIPIENT_EMAIL : '');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    if (!csrf_validate()) {
        header('Location: ' . u('settings', 'app'));
        exit;
    }

    $ownerEmail      = trim((string) ($_POST['owner_email']               ?? ''));
    $threshold       = max(1, (int) ($_POST['recycle_threshold_default'] ?? 10));
    $lookahead       = max(1, (int) ($_POST['recycle_lookahead_days']    ?? 30));
    $minPosts        = max(1, (int) ($_POST['schedule_min_posts']        ?? 5));
    $notifyFailure   = isset($_POST['notify_post_failure']) ? '1' : '0';
    $notifyFrequency = in_array($_POST['notify_recap_frequency'] ?? '', ['never', 'daily', 'weekly'], true)
                           ? (string) $_POST['notify_recap_frequency'] : 'never';
    $notifyEmail     = trim((string) ($_POST['notify_recipient_email'] ?? ''));

    $errors = [];
    if ($ownerEmail !== '' && !filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Owner email is not a valid email address.';
    }
    if ($notifyEmail !== '' && !filter_var($notifyEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Notification recipient is not a valid email address.';
    }

    if (!empty($errors)) {
        $template->set('saveError',       implode(' ', $errors));
        $template->set('ownerEmail',      $ownerEmail);
        $template->set('threshold',       $threshold);
        $template->set('lookahead',       $lookahead);
        $template->set('minPosts',        $minPosts);
        $template->set('notifyFailure',   $notifyFailure);
        $template->set('notifyFrequency', $notifyFrequency);
        $template->set('notifyEmail',     $notifyEmail);
        return;
    }

    $updates = [
        'owner_email'               => $ownerEmail,
        'recycle_threshold_default' => (string) $threshold,
        'recycle_lookahead_days'    => (string) $lookahead,
        'schedule_min_posts'        => (string) $minPosts,
        'notify_post_failure'       => $notifyFailure,
        'notify_recap_frequency'    => $notifyFrequency,
        'notify_recipient_email'    => $notifyEmail,
    ];
    save_admin_settings($dbh, $updates);

    $template->set('ownerEmail',      $ownerEmail);
    $template->set('threshold',       $threshold);
    $template->set('lookahead',       $lookahead);
    $template->set('minPosts',        $minPosts);
    $template->set('notifyFailure',   $notifyFailure);
    $template->set('notifyFrequency', $notifyFrequency);
    $template->set('notifyEmail',     $notifyEmail);
    $template->set('saveSuccess', true);
}

function save_admin_settings(PDO $dbh, array $updates): void {
    $stmt = $dbh->prepare(
        'INSERT INTO admin_settings (setting_key, setting_val) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)'
    );
    foreach ($updates as $key => $val) {
        $stmt->execute([$key, $val]);
    }
}
