<?php
declare(strict_types=1);

use SocialTurn\Services\StorageService;
use SocialTurn\Services\TwitterService;
use SocialTurn\Services\FacebookService;

/**
 * Platform connection controller — Admin only (type=1).
 *
 * Orchestrates OAuth flows for Twitter (1.0a) and Facebook/Instagram (2.0).
 * All token logic lives in service classes — this controller only manages
 * redirects, SESSION handshake state, and connected_platforms DB writes.
 *
 * Tokens never appear in views, logs, or HTTP responses.
 * SESSION oauth state is always cleared on success, failure, or cancel.
 *
 * Functions:
 *   twitter()            — Initiate Twitter OAuth 1.0a
 *   twitterCallback()    — Receive Twitter verifier, exchange, store
 *   facebook()           — Initiate Facebook/Instagram OAuth 2.0
 *   facebookCallback()   — Receive code, exchange tokens, discover pages
 *   pages()              — Render page/account selection UI
 *   savePage()           — Save a selected Facebook Page or Instagram account
 *   cancel()             — Clear SESSION state, return to accounts
 *   disconnect()         — Delete a connected_platforms row
 */

checkPermission(1);

// -----------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------

function connect_companyId(): int
{
    return (int) ($_SESSION['user']['company_id'] ?? $_SESSION['user']['companyid'] ?? 0);
}

// -----------------------------------------------------------------------
// Twitter — OAuth 1.0a
// -----------------------------------------------------------------------

/**
 * Initiate Twitter OAuth 1.0a.
 *
 * Requests a request token from Twitter and redirects to the authorization URL.
 * Stores the request token secret in SESSION for use in twitterCallback().
 */
function twitter(): void
{
    $service = new TwitterService(new StorageService());

    try {
        $requestToken = $service->getRequestToken(u('connect', 'twitterCallback'));
    } catch (Throwable $e) {
        error_log('Twitter connect error: ' . $e->getMessage());
        $_SESSION['notification'] = [
            'type'    => 'error',
            'message' => 'Could not connect to Twitter. Check Platform Credentials in Settings.',
        ];
        header('Location: ' . u('accounts'));
        exit;
    }

    if (empty($requestToken['oauth_token']) || empty($requestToken['oauth_token_secret'])) {
        $_SESSION['notification'] = [
            'type'    => 'error',
            'message' => 'Twitter did not return a valid request token. Check your app credentials.',
        ];
        header('Location: ' . u('accounts'));
        exit;
    }

    $_SESSION['oauth']['twitter_request_secret'] = $requestToken['oauth_token_secret'];

    header('Location: ' . $service->getAuthorizeUrl($requestToken['oauth_token']));
    exit;
}

/**
 * Twitter OAuth 1.0a callback.
 *
 * Exchanges the verifier for a permanent access token pair, verifies it,
 * then upserts a connected_platforms row. SESSION state is cleared on
 * all outcomes — success, failure, or user cancellation.
 */
function twitterCallback(): void
{
    global $dbh;

    $oauthToken    = (string) ($_GET['oauth_token']    ?? '');
    $oauthVerifier = (string) ($_GET['oauth_verifier'] ?? '');
    $requestSecret = (string) ($_SESSION['oauth']['twitter_request_secret'] ?? '');

    if ($oauthToken === '' || $oauthVerifier === '' || $requestSecret === '') {
        unset($_SESSION['oauth']);
        $_SESSION['notification'] = [
            'type'    => 'error',
            'message' => 'Twitter authorization was cancelled or did not complete.',
        ];
        header('Location: ' . u('accounts'));
        exit;
    }

    $service = new TwitterService(new StorageService());

    try {
        $accessToken = $service->getAccessToken($oauthToken, $requestSecret, $oauthVerifier);
    } catch (Throwable) {
        unset($_SESSION['oauth']);
        $_SESSION['notification'] = [
            'type'    => 'error',
            'message' => 'Twitter token exchange failed. Please try again.',
        ];
        header('Location: ' . u('accounts'));
        exit;
    }

    if (empty($accessToken['oauth_token']) || empty($accessToken['oauth_token_secret'])) {
        unset($_SESSION['oauth']);
        $_SESSION['notification'] = [
            'type'    => 'error',
            'message' => 'Twitter did not return a valid access token. Please try again.',
        ];
        header('Location: ' . u('accounts'));
        exit;
    }

    $token       = (string) $accessToken['oauth_token'];
    $tokenSecret = (string) $accessToken['oauth_token_secret'];
    $twitterId   = (string) ($accessToken['user_id']     ?? '');
    $screenName  = (string) ($accessToken['screen_name'] ?? '');

    if (!$service->verifyToken($token, $tokenSecret)) {
        unset($_SESSION['oauth']);
        $_SESSION['notification'] = [
            'type'    => 'error',
            'message' => 'Twitter token verification failed. Please try again.',
        ];
        header('Location: ' . u('accounts'));
        exit;
    }

    $companyId = connect_companyId();

    // Upsert: re-authorizing the same account updates its token rather than duplicating.
    // platform_name intentionally omitted — Twitter's OAuth 1.0a callback does not
    // return a display name; screen_name stored as platform_username is sufficient.
    $dbh->prepare(
        "INSERT INTO connected_platforms
             (company_id, platform, platform_account_id, platform_username, access_token, token_secret, is_active)
         VALUES (?, 'twitter', ?, ?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE
             access_token      = VALUES(access_token),
             token_secret      = VALUES(token_secret),
             platform_username = VALUES(platform_username),
             is_active         = 1,
             updated_at        = NOW()"
    )->execute([$companyId, $twitterId, $screenName, $token, $tokenSecret]);

    unset($_SESSION['oauth']);

    $_SESSION['notification'] = [
        'type'    => 'success',
        'message' => 'Twitter account @' . htmlspecialchars($screenName, ENT_QUOTES, 'UTF-8') . ' connected.',
    ];
    header('Location: ' . u('accounts'));
    exit;
}

// -----------------------------------------------------------------------
// Facebook + Instagram — OAuth 2.0 (shared flow)
// -----------------------------------------------------------------------

/**
 * Initiate Facebook/Instagram OAuth 2.0.
 *
 * Requests both Facebook Page and Instagram Business scopes in one dialog.
 * One authorization covers both platforms — no separate Instagram flow needed.
 * A CSRF state key is generated and stored in SESSION for validation in the callback.
 */
function facebook(): void
{
    if (!defined('META_APP_ID') || !defined('META_APP_SECRET')
        || META_APP_ID     === 'your_facebook_app_id'
        || META_APP_SECRET === 'your_facebook_app_secret'
    ) {
        $_SESSION['notification'] = [
            'type'    => 'error',
            'message' => 'Could not connect to Facebook. Check Platform Credentials in Settings.',
        ];
        header('Location: ' . u('accounts'));
        exit;
    }

    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth']['facebook_state'] = $state;

    $params = http_build_query([
        'client_id'     => META_APP_ID,
        'redirect_uri'  => u('connect', 'facebookCallback'),
        'scope'         => 'pages_show_list,pages_read_engagement,pages_manage_posts,instagram_basic,instagram_content_publish',
        'state'         => $state,
        'response_type' => 'code',
    ]);

    header('Location: https://www.facebook.com/v19.0/dialog/oauth?' . $params);
    exit;
}

/**
 * Facebook/Instagram OAuth 2.0 callback.
 *
 * Validates CSRF state, exchanges the authorization code for a short-lived
 * user token, exchanges that for a long-lived user token (~60 days), then
 * calls getPagesWithInstagram() to discover Pages and Instagram Business accounts.
 *
 * Stores two SESSION indexes keyed by platform_account_id for O(1) token lookup
 * in savePage(). The view never sees any token.
 */
function facebookCallback(): void
{
    global $dbh;

    // CSRF state check
    $returnedState = (string) ($_GET['state'] ?? '');
    $storedState   = (string) ($_SESSION['oauth']['facebook_state'] ?? '');

    if ($returnedState === '' || !hash_equals($storedState, $returnedState)) {
        unset($_SESSION['oauth']);
        $_SESSION['notification'] = [
            'type'    => 'error',
            'message' => 'Facebook authorization state mismatch. Please try again.',
        ];
        header('Location: ' . u('accounts'));
        exit;
    }

    $code = (string) ($_GET['code'] ?? '');

    if ($code === '') {
        unset($_SESSION['oauth']);
        $_SESSION['notification'] = [
            'type'    => 'error',
            'message' => 'Facebook authorization was cancelled.',
        ];
        header('Location: ' . u('accounts'));
        exit;
    }

    $service = new FacebookService($dbh, new StorageService());

    $shortLivedToken = $service->exchangeCodeForToken($code, u('connect', 'facebookCallback'));

    if ($shortLivedToken === null) {
        unset($_SESSION['oauth']);
        $_SESSION['notification'] = [
            'type'    => 'error',
            'message' => 'Facebook token exchange failed. Please try again.',
        ];
        header('Location: ' . u('accounts'));
        exit;
    }

    $longLivedData = $service->exchangeForLongLivedToken($shortLivedToken);

    if ($longLivedData === null) {
        unset($_SESSION['oauth']);
        $_SESSION['notification'] = [
            'type'    => 'error',
            'message' => 'Could not obtain a long-lived Facebook token. Please try again.',
        ];
        header('Location: ' . u('accounts'));
        exit;
    }

    $rawPages = $service->getPagesWithInstagram($longLivedData['access_token']);

    // Build SESSION indexes keyed by platform_account_id for O(1) token lookup in savePage().
    // Tokens are stored server-side only — never passed to the view.
    $pages     = [];
    $instagram = [];

    foreach ($rawPages as $page) {
        if (empty($page['id']) || empty($page['access_token'])) {
            continue;
        }

        $pages[(string) $page['id']] = [
            'id'           => (string) $page['id'],
            'name'         => (string) ($page['name'] ?? ''),
            'access_token' => (string) $page['access_token'],
        ];

        if (!empty($page['instagram_business_account']['id'])) {
            $ig = $page['instagram_business_account'];
            $instagram[(string) $ig['id']] = [
                'id'                => (string) $ig['id'],
                'name'              => (string) ($ig['name']     ?? ''),
                'username'          => (string) ($ig['username'] ?? ''),
                'page_access_token' => (string) $page['access_token'],
            ];
        }
    }

    $_SESSION['oauth']['facebook_pages']     = $pages;
    $_SESSION['oauth']['facebook_instagram'] = $instagram;
    $_SESSION['oauth']['expires']            = time() + 600;

    header('Location: ' . u('connect', 'pages'));
    exit;
}

/**
 * Page/account selection UI.
 *
 * Reads the discovered pages and Instagram accounts from SESSION.
 * Passes display data only — no token fields — to the view.
 * Redirects to accounts if SESSION state is absent or empty.
 */
function pages(): void
{
    global $template;

    if (($_SESSION['oauth']['expires'] ?? 0) < time()) {
        unset($_SESSION['oauth']);
        $_SESSION['notification'] = [
            'type'    => 'error',
            'message' => 'Session expired. Please reconnect.',
        ];
        header('Location: ' . u('accounts'));
        exit;
    }

    $pages     = $_SESSION['oauth']['facebook_pages']     ?? [];
    $instagram = $_SESSION['oauth']['facebook_instagram'] ?? [];

    if (empty($pages) && empty($instagram)) {
        unset($_SESSION['oauth']);
        $_SESSION['notification'] = [
            'type'    => 'error',
            'message' => 'No Facebook Pages found. Make sure your Facebook account manages at least one Page, then try again.',
        ];
        header('Location: ' . u('accounts'));
        exit;
    }

    // Strip tokens — view receives display data only
    $pageList = [];
    foreach ($pages as $page) {
        $pageList[] = [
            'id'   => $page['id'],
            'name' => $page['name'],
        ];
    }

    $igList = [];
    foreach ($instagram as $ig) {
        $igList[] = [
            'id'       => $ig['id'],
            'name'     => $ig['name'],
            'username' => $ig['username'],
        ];
    }

    $template->set('pageList',  $pageList);
    $template->set('igList',    $igList);
    $template->set('csrfToken', csrf_token());
}

/**
 * Save a selected Facebook Page or Instagram Business account.
 *
 * The POST body carries only display identifiers: platform, platform_account_id,
 * platform_name, platform_username. The access token is looked up from SESSION
 * using platform_account_id as the key — it is never present in the request body
 * or HTML source.
 *
 * Page Access Tokens obtained from a long-lived user token do not expire.
 * token_expires_at is stored as NULL.
 *
 * Upsert: re-connecting the same account refreshes its token without duplicating.
 * SESSION is cleared on all outcomes.
 */
function savePage(): void
{
    global $dbh;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ' . u('connect', 'pages'));
        exit;
    }

    if (!csrf_validate()) {
        header('Location: ' . u('connect', 'pages'));
        exit;
    }

    $platform          = (string) ($_POST['platform']            ?? '');
    $platformAccountId = trim((string) ($_POST['platform_account_id'] ?? ''));
    $platformName      = mb_substr(trim((string) ($_POST['platform_name']     ?? '')), 0, 255);
    $platformUsername  = mb_substr(trim((string) ($_POST['platform_username'] ?? '')), 0, 50);

    if (!in_array($platform, ['facebook', 'instagram'], true) || $platformAccountId === '') {
        header('Location: ' . u('connect', 'pages'));
        exit;
    }

    // Token lookup from SESSION — never from POST body
    if ($platform === 'facebook') {
        $token = $_SESSION['oauth']['facebook_pages'][$platformAccountId]['access_token'] ?? null;
    } else {
        $token = $_SESSION['oauth']['facebook_instagram'][$platformAccountId]['page_access_token'] ?? null;
    }

    if ($token === null) {
        unset($_SESSION['oauth']);
        $_SESSION['notification'] = [
            'type'    => 'error',
            'message' => 'Session expired. Please reconnect.',
        ];
        header('Location: ' . u('accounts'));
        exit;
    }

    $companyId = connect_companyId();

    // Page Access Tokens from a long-lived user token do not expire — token_expires_at = NULL.
    // Upsert: re-connecting the same account refreshes its token without creating duplicates.
    $dbh->prepare(
        'INSERT INTO connected_platforms
             (company_id, platform, platform_account_id, platform_name, platform_username, access_token, token_expires_at, is_active)
         VALUES (?, ?, ?, ?, ?, ?, NULL, 1)
         ON DUPLICATE KEY UPDATE
             platform_name     = VALUES(platform_name),
             platform_username = VALUES(platform_username),
             access_token      = VALUES(access_token),
             token_expires_at  = NULL,
             is_active         = 1,
             updated_at        = NOW()'
    )->execute([$companyId, $platform, $platformAccountId, $platformName, $platformUsername, $token]);

    unset($_SESSION['oauth']);

    $label = $platformUsername !== '' ? '@' . $platformUsername : $platformName;
    $_SESSION['notification'] = [
        'type'    => 'success',
        'message' => ucfirst($platform) . ' account "' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '" connected.',
    ];
    header('Location: ' . u('accounts'));
    exit;
}

/**
 * Cancel — clears OAuth SESSION state and returns to accounts without saving.
 */
function cancel(): void
{
    unset($_SESSION['oauth']);
    header('Location: ' . u('accounts'));
    exit;
}

/**
 * Disconnect a connected platform.
 *
 * Blocked at the application layer if any accounts reference this connection —
 * the DB FK constraint also enforces this, but the app check gives a readable
 * message instead of a DB exception.
 *
 * Token revocation is not attempted — best-effort, deferred to a future phase.
 */
function disconnect(): void
{
    global $dbh;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ' . u('accounts'));
        exit;
    }

    if (!csrf_validate()) {
        header('Location: ' . u('accounts'));
        exit;
    }

    $companyId           = connect_companyId();
    $connectedPlatformId = (int) ($_POST['connected_platform_id'] ?? 0);

    if ($connectedPlatformId === 0) {
        header('Location: ' . u('accounts'));
        exit;
    }

    // Verify ownership before touching the row
    $stmt = $dbh->prepare(
        'SELECT id, platform, platform_name, platform_username
           FROM connected_platforms
          WHERE id = ? AND company_id = ?'
    );
    $stmt->execute([$connectedPlatformId, $companyId]);
    $connection = $stmt->fetch(PDO::FETCH_ASSOC);

    if (empty($connection['id'])) {
        error404();
    }

    // App-layer guard: surface a readable error before the FK constraint fires.
    $stmt = $dbh->prepare(
        'SELECT COUNT(*) FROM accounts WHERE connected_platform_id = ? AND is_active = 1'
    );
    $stmt->execute([$connectedPlatformId]);

    if ((int) $stmt->fetchColumn() > 0) {
        $_SESSION['notification'] = [
            'type'    => 'error',
            'message' => 'Remove all accounts using this connection before disconnecting.',
        ];
        header('Location: ' . u('accounts'));
        exit;
    }

    $dbh->prepare(
        'DELETE FROM connected_platforms WHERE id = ? AND company_id = ?'
    )->execute([$connectedPlatformId, $companyId]);

    $label = !empty($connection['platform_username'])
        ? '@' . htmlspecialchars((string) $connection['platform_username'], ENT_QUOTES, 'UTF-8')
        : htmlspecialchars((string) $connection['platform_name'], ENT_QUOTES, 'UTF-8');

    $_SESSION['notification'] = [
        'type'    => 'success',
        'message' => ucfirst((string) $connection['platform']) . ' account "' . $label . '" disconnected.',
    ];
    header('Location: ' . u('accounts'));
    exit;
}
