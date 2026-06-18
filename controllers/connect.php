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
 * redirects, oauth_states DB handshake state, and connected_platforms DB writes.
 *
 * App credentials (Consumer Key/Secret for Twitter; App ID/Secret for Meta) are
 * entered per-connection in a credential form before each OAuth flow. They are
 * persisted in oauth_states during the handshake and written to connected_platforms
 * on success — never stored as global settings.
 *
 * Tokens never appear in views, logs, or HTTP responses.
 * oauth_states rows are deleted immediately on use (consumed once, prevent replay).
 * SESSION is used only for post-callback page-selection state (Facebook) and
 * flash notifications — not for OAuth handshake CSRF or request token secrets.
 *
 * Functions:
 *   index()              — List all connected_platforms rows with workspace count
 *   twitter()            — GET: credential form; POST: initiate Twitter OAuth 1.0a
 *   twitterCallback()    — Receive Twitter verifier, exchange, store
 *   facebook()           — GET: credential form; POST: initiate Facebook/Instagram OAuth 2.0
 *   facebookCallback()   — Receive code, exchange tokens, discover pages
 *   pages()              — Render page/account selection UI
 *   savePage()           — Save a selected Facebook Page or Instagram account
 *   cancel()             — Clear SESSION state, return to Connections
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

function connect_userId(): int
{
    return (int) ($_SESSION['user']['loggedin'] ?? 0);
}

// -----------------------------------------------------------------------
// Connections listing
// -----------------------------------------------------------------------

/**
 * List all connected_platforms rows for the company, with active workspace count.
 */
function index(): void
{
    global $dbh, $template;

    $companyId = connect_companyId();

    $stmt = $dbh->prepare(
        'SELECT cp.id, cp.platform, cp.platform_name, cp.platform_username,
                cp.is_active, cp.token_expires_at, cp.created_at,
                COUNT(a.id) AS workspace_count
           FROM connected_platforms cp
           LEFT JOIN accounts a ON a.connected_platform_id = cp.id AND a.is_active = 1
          WHERE cp.company_id = ?
          GROUP BY cp.id
          ORDER BY cp.platform ASC, cp.platform_name ASC'
    );
    $stmt->execute([$companyId]);
    $connections = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $template->set('connections', $connections);
    $template->set('csrfToken',   csrf_token());
}

// -----------------------------------------------------------------------
// Twitter — OAuth 1.0a
// -----------------------------------------------------------------------

/**
 * GET: Show the Twitter credential entry form.
 * POST: Validate credentials, initiate Twitter OAuth 1.0a.
 *
 * Credentials (Consumer Key and Consumer Secret) are submitted in the POST body,
 * stored in oauth_states for the duration of the handshake, and written to
 * connected_platforms on successful callback. They are never stored as global settings.
 */
function twitter(): void
{
    global $dbh, $template;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $template->set('callbackUrl', BASE_URL . 'index.php?c=connect&a=twitterCallback');
        $template->set('csrfToken', csrf_token());
        return;
    }

    if (!csrf_validate()) {
        header('Location: ' . u('connect', 'twitter'));
        exit;
    }

    $appKey    = trim((string) ($_POST['app_key']    ?? ''));
    $appSecret = trim((string) ($_POST['app_secret'] ?? ''));

    if ($appKey === '' || $appSecret === '') {
        $_SESSION['notification'] = [
            'type'    => 'error',
            'message' => 'Consumer Key and Consumer Secret are both required.',
        ];
        header('Location: ' . u('connect', 'twitter'));
        exit;
    }

    $service = new TwitterService(new StorageService(), $appKey, $appSecret);

    try {
        $requestToken = $service->getRequestToken(u('connect', 'twitterCallback'));
    } catch (Throwable $e) {
        error_log('Twitter connect error: ' . $e->getMessage());
        $_SESSION['notification'] = [
            'type'    => 'error',
            'message' => 'Could not reach Twitter. Check your Consumer Key and Secret.',
        ];
        header('Location: ' . u('connect', 'twitter'));
        exit;
    }

    if (empty($requestToken['oauth_token']) || empty($requestToken['oauth_token_secret'])) {
        $_SESSION['notification'] = [
            'type'    => 'error',
            'message' => 'Twitter did not return a valid request token. Check your app credentials.',
        ];
        header('Location: ' . u('connect', 'twitter'));
        exit;
    }

    $dbh->prepare(
        "INSERT INTO oauth_states
             (state_key, platform, user_id, request_token, request_token_secret, app_key, app_secret)
         VALUES (?, 'twitter', ?, ?, ?, ?, ?)"
    )->execute([
        bin2hex(random_bytes(32)),
        connect_userId(),
        $requestToken['oauth_token'],
        $requestToken['oauth_token_secret'],
        $appKey,
        $appSecret,
    ]);

    header('Location: ' . $service->getAuthorizeUrl($requestToken['oauth_token']));
    exit;
}

/**
 * Twitter OAuth 1.0a callback.
 *
 * Looks up handshake state in oauth_states using oauth_token (the request token),
 * which Twitter echoes back in the callback URL. The state row is deleted immediately
 * on retrieval — consumed once, prevents replay. Rows older than 15 minutes are
 * treated as expired. On success, upserts a connected_platforms row including the
 * app credentials that were stored during the handshake.
 */
function twitterCallback(): void
{
    global $dbh;

    $oauthToken    = (string) ($_GET['oauth_token']    ?? '');
    $oauthVerifier = (string) ($_GET['oauth_verifier'] ?? '');

    if ($oauthToken === '' || $oauthVerifier === '') {
        $_SESSION['notification'] = [
            'type'    => 'error',
            'message' => 'Twitter authorization was cancelled or did not complete.',
        ];
        header('Location: ' . u('connect', 'index'));
        exit;
    }

    $stmt = $dbh->prepare(
        "SELECT id, request_token_secret, app_key, app_secret, created_at
           FROM oauth_states
          WHERE request_token = ? AND platform = 'twitter'
          LIMIT 1"
    );
    $stmt->execute([$oauthToken]);
    $stateRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (empty($stateRow)) {
        $_SESSION['notification'] = [
            'type'    => 'error',
            'message' => 'Twitter authorization state not found. Please try again.',
        ];
        header('Location: ' . u('connect', 'index'));
        exit;
    }

    if (new DateTimeImmutable($stateRow['created_at']) < new DateTimeImmutable('-15 minutes')) {
        $dbh->prepare('DELETE FROM oauth_states WHERE id = ?')->execute([$stateRow['id']]);
        $_SESSION['notification'] = [
            'type'    => 'error',
            'message' => 'Twitter authorization expired. Please try again.',
        ];
        header('Location: ' . u('connect', 'index'));
        exit;
    }

    $requestSecret = (string) $stateRow['request_token_secret'];
    $appKey        = (string) ($stateRow['app_key']    ?? '');
    $appSecret     = (string) ($stateRow['app_secret'] ?? '');

    // Delete immediately before the token exchange — prevents replay.
    $dbh->prepare('DELETE FROM oauth_states WHERE id = ?')->execute([$stateRow['id']]);

    $service = new TwitterService(new StorageService(), $appKey, $appSecret);

    try {
        $accessToken = $service->getAccessToken($oauthToken, $requestSecret, $oauthVerifier);
    } catch (Throwable) {
        $_SESSION['notification'] = [
            'type'    => 'error',
            'message' => 'Twitter token exchange failed. Please try again.',
        ];
        header('Location: ' . u('connect', 'index'));
        exit;
    }

    if (empty($accessToken['oauth_token']) || empty($accessToken['oauth_token_secret'])) {
        $_SESSION['notification'] = [
            'type'    => 'error',
            'message' => 'Twitter did not return a valid access token. Please try again.',
        ];
        header('Location: ' . u('connect', 'index'));
        exit;
    }

    $token       = (string) $accessToken['oauth_token'];
    $tokenSecret = (string) $accessToken['oauth_token_secret'];
    $twitterId   = (string) ($accessToken['user_id']     ?? '');
    $screenName  = (string) ($accessToken['screen_name'] ?? '');

    if (!$service->verifyToken($token, $tokenSecret)) {
        $_SESSION['notification'] = [
            'type'    => 'error',
            'message' => 'Twitter token verification failed. Please try again.',
        ];
        header('Location: ' . u('connect', 'index'));
        exit;
    }

    $companyId = connect_companyId();

    // Upsert: re-authorizing the same account updates its token and credentials
    // rather than creating a duplicate row.
    // platform_name intentionally omitted — Twitter's OAuth 1.0a callback does not
    // return a display name; screen_name stored as platform_username is sufficient.
    $dbh->prepare(
        "INSERT INTO connected_platforms
             (company_id, platform, platform_account_id, platform_username,
              access_token, token_secret, app_key, app_secret, is_active)
         VALUES (?, 'twitter', ?, ?, ?, ?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE
             access_token      = VALUES(access_token),
             token_secret      = VALUES(token_secret),
             app_key           = VALUES(app_key),
             app_secret        = VALUES(app_secret),
             platform_username = VALUES(platform_username),
             is_active         = 1,
             updated_at        = NOW()"
    )->execute([$companyId, $twitterId, $screenName, $token, $tokenSecret, $appKey, $appSecret]);

    $_SESSION['notification'] = [
        'type'    => 'success',
        'message' => 'Twitter account @' . htmlspecialchars($screenName, ENT_QUOTES, 'UTF-8') . ' connected.',
    ];
    header('Location: ' . u('connect', 'index'));
    exit;
}

// -----------------------------------------------------------------------
// Facebook + Instagram — OAuth 2.0 (shared flow)
// -----------------------------------------------------------------------

/**
 * GET: Show the Facebook/Instagram credential entry form.
 * POST: Validate credentials, initiate Facebook/Instagram OAuth 2.0.
 *
 * One authorization covers both Facebook Pages and Instagram Business accounts —
 * no separate Instagram flow needed. A CSRF state key is persisted in oauth_states
 * (not SESSION) so concurrent flows in the same browser session cannot overwrite
 * each other. App credentials are stored in oauth_states for the handshake duration
 * and written to connected_platforms on successful page selection.
 */
function facebook(): void
{
    global $dbh, $template;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $template->set('callbackUrl', BASE_URL . 'index.php?c=connect&a=facebookCallback');
        $template->set('csrfToken', csrf_token());
        return;
    }

    if (!csrf_validate()) {
        header('Location: ' . u('connect', 'facebook'));
        exit;
    }

    $appId     = trim((string) ($_POST['app_key']    ?? ''));
    $appSecret = trim((string) ($_POST['app_secret'] ?? ''));

    if ($appId === '' || $appSecret === '') {
        $_SESSION['notification'] = [
            'type'    => 'error',
            'message' => 'App ID and App Secret are both required.',
        ];
        header('Location: ' . u('connect', 'facebook'));
        exit;
    }

    $stateKey = bin2hex(random_bytes(32));

    $dbh->prepare(
        "INSERT INTO oauth_states (state_key, platform, user_id, app_key, app_secret)
         VALUES (?, 'facebook', ?, ?, ?)"
    )->execute([$stateKey, connect_userId(), $appId, $appSecret]);

    $params = http_build_query([
        'client_id'     => $appId,
        'redirect_uri'  => u('connect', 'facebookCallback'),
        'scope'         => 'pages_show_list,pages_read_engagement,pages_manage_posts,instagram_basic,instagram_content_publish',
        'state'         => $stateKey,
        'response_type' => 'code',
    ]);

    header('Location: https://www.facebook.com/v19.0/dialog/oauth?' . $params);
    exit;
}

/**
 * Facebook/Instagram OAuth 2.0 callback.
 *
 * Validates CSRF state via oauth_states lookup (not SESSION) — the state_key
 * stored in the DB is compared against the state parameter Facebook echoes back.
 * The state row is deleted immediately on retrieval (consumed once, prevents replay).
 * Rows older than 15 minutes are treated as expired.
 *
 * After state validation, reads app credentials from the oauth_states row,
 * exchanges the code for tokens and discovers Pages and Instagram Business accounts.
 * Stores two SESSION indexes keyed by platform_account_id for O(1) token lookup in
 * savePage(). App credentials are also stored in SESSION to be written to
 * connected_platforms at savePage() time. The view never sees any token.
 */
function facebookCallback(): void
{
    global $dbh;

    $returnedState = (string) ($_GET['state'] ?? '');

    if ($returnedState === '') {
        $_SESSION['notification'] = [
            'type'    => 'error',
            'message' => 'Facebook authorization state missing. Please try again.',
        ];
        header('Location: ' . u('connect', 'index'));
        exit;
    }

    $stmt = $dbh->prepare(
        "SELECT id, app_key, app_secret, created_at
           FROM oauth_states
          WHERE state_key = ? AND platform = 'facebook'
          LIMIT 1"
    );
    $stmt->execute([$returnedState]);
    $stateRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (empty($stateRow)) {
        $_SESSION['notification'] = [
            'type'    => 'error',
            'message' => 'Facebook authorization state not found. Please try again.',
        ];
        header('Location: ' . u('connect', 'index'));
        exit;
    }

    if (new DateTimeImmutable($stateRow['created_at']) < new DateTimeImmutable('-15 minutes')) {
        $dbh->prepare('DELETE FROM oauth_states WHERE id = ?')->execute([$stateRow['id']]);
        $_SESSION['notification'] = [
            'type'    => 'error',
            'message' => 'Facebook authorization expired. Please try again.',
        ];
        header('Location: ' . u('connect', 'index'));
        exit;
    }

    $appId     = (string) ($stateRow['app_key']    ?? '');
    $appSecret = (string) ($stateRow['app_secret'] ?? '');

    // Delete immediately before token exchange — prevents replay.
    $dbh->prepare('DELETE FROM oauth_states WHERE id = ?')->execute([$stateRow['id']]);

    $code = (string) ($_GET['code'] ?? '');

    if ($code === '') {
        $_SESSION['notification'] = [
            'type'    => 'error',
            'message' => 'Facebook authorization was cancelled.',
        ];
        header('Location: ' . u('connect', 'index'));
        exit;
    }

    $service = new FacebookService($dbh, new StorageService(), $appId, $appSecret);

    $shortLivedToken = $service->exchangeCodeForToken($code, u('connect', 'facebookCallback'));

    if ($shortLivedToken === null) {
        $_SESSION['notification'] = [
            'type'    => 'error',
            'message' => 'Facebook token exchange failed. Please try again.',
        ];
        header('Location: ' . u('connect', 'index'));
        exit;
    }

    $longLivedData = $service->exchangeForLongLivedToken($shortLivedToken);

    if ($longLivedData === null) {
        $_SESSION['notification'] = [
            'type'    => 'error',
            'message' => 'Could not obtain a long-lived Facebook token. Please try again.',
        ];
        header('Location: ' . u('connect', 'index'));
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

    // App credentials stored alongside page data so savePage() can write them
    // to connected_platforms. The handshake row is already deleted at this point.
    $_SESSION['oauth']['facebook_pages']     = $pages;
    $_SESSION['oauth']['facebook_instagram'] = $instagram;
    $_SESSION['oauth']['app_key']            = $appId;
    $_SESSION['oauth']['app_secret']         = $appSecret;
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
        header('Location: ' . u('connect', 'index'));
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
        header('Location: ' . u('connect', 'index'));
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
 * or HTML source. App credentials are read from SESSION and written to
 * connected_platforms alongside the OAuth token.
 *
 * Page Access Tokens obtained from a long-lived user token do not expire.
 * token_expires_at is stored as NULL.
 *
 * Upsert: re-connecting the same account refreshes its token and credentials
 * without creating duplicates. SESSION is cleared on all outcomes.
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

    // Token and credential lookup from SESSION — never from POST body
    if ($platform === 'facebook') {
        $token = $_SESSION['oauth']['facebook_pages'][$platformAccountId]['access_token'] ?? null;
    } else {
        $token = $_SESSION['oauth']['facebook_instagram'][$platformAccountId]['page_access_token'] ?? null;
    }

    $appKey    = (string) ($_SESSION['oauth']['app_key']    ?? '');
    $appSecret = (string) ($_SESSION['oauth']['app_secret'] ?? '');

    if ($token === null) {
        unset($_SESSION['oauth']);
        $_SESSION['notification'] = [
            'type'    => 'error',
            'message' => 'Session expired. Please reconnect.',
        ];
        header('Location: ' . u('connect', 'index'));
        exit;
    }

    $companyId = connect_companyId();

    // Page Access Tokens from a long-lived user token do not expire — token_expires_at = NULL.
    // Upsert: re-connecting the same account refreshes its token and credentials without duplicates.
    $dbh->prepare(
        'INSERT INTO connected_platforms
             (company_id, platform, platform_account_id, platform_name, platform_username,
              access_token, token_expires_at, app_key, app_secret, is_active)
         VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, 1)
         ON DUPLICATE KEY UPDATE
             platform_name     = VALUES(platform_name),
             platform_username = VALUES(platform_username),
             access_token      = VALUES(access_token),
             token_expires_at  = NULL,
             app_key           = VALUES(app_key),
             app_secret        = VALUES(app_secret),
             is_active         = 1,
             updated_at        = NOW()'
    )->execute([$companyId, $platform, $platformAccountId, $platformName, $platformUsername,
                $token, $appKey, $appSecret]);

    unset($_SESSION['oauth']);

    $label = $platformUsername !== '' ? '@' . $platformUsername : $platformName;
    $_SESSION['notification'] = [
        'type'    => 'success',
        'message' => ucfirst($platform) . ' account "' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '" connected.',
    ];
    header('Location: ' . u('connect', 'index'));
    exit;
}

/**
 * Cancel — clears OAuth SESSION state and returns to accounts without saving.
 */
function cancel(): void
{
    unset($_SESSION['oauth']);
    header('Location: ' . u('connect', 'index'));
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
        header('Location: ' . u('connect', 'index'));
        exit;
    }

    if (!csrf_validate()) {
        header('Location: ' . u('connect', 'index'));
        exit;
    }

    $companyId           = connect_companyId();
    $connectedPlatformId = (int) ($_POST['connected_platform_id'] ?? 0);

    if ($connectedPlatformId === 0) {
        header('Location: ' . u('connect', 'index'));
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
            'message' => 'Remove all workspaces using this connection before disconnecting.',
        ];
        header('Location: ' . u('connect', 'index'));
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
    header('Location: ' . u('connect', 'index'));
    exit;
}
