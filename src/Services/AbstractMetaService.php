<?php
declare(strict_types=1);

namespace SocialTurn\Services;

use GuzzleHttp\Client;
use PDO;
use Throwable;

/**
 * AbstractMetaService
 *
 * Shared infrastructure for Meta platform services (Facebook, Instagram).
 * Both platforms authenticate via tokens issued by the Meta Graph API,
 * share the same token exchange endpoint, and post through the same
 * Graph API base URI.
 *
 * Child classes must implement post() and verifyToken().
 * All other methods are inherited.
 *
 * App credentials ($appId, $appSecret) are injected at construction and stored
 * per connected_platforms row. refreshToken() re-reads them from the DB row
 * rather than using the constructor-provided values, so a single maintenance
 * call works correctly regardless of which instance was created.
 *
 * Token lifecycle: Meta tokens expire in ~60 days. refreshToken() performs
 * the fb_exchange_token exchange and persists the new token automatically.
 * isNearExpiry() lets the cron controller proactively refresh before expiry.
 *
 * HTTP: Guzzle with http_errors => false. Graph API error details are in the
 * response body, not the HTTP status code. 4xx/5xx are decoded and returned
 * so parsePostResponse() can inspect the error key. Network-level failures
 * (timeout, DNS) bubble to the calling method and are caught there.
 */
abstract class AbstractMetaService
{
    protected const GRAPH_BASE_URI              = 'https://graph.facebook.com/v19.0';
    protected const TOKEN_REFRESH_THRESHOLD_DAYS = 7;

    private ?Client $client = null;

    public function __construct(
        protected readonly PDO            $dbh,
        protected readonly StorageService $storage,
        protected readonly string         $appId,
        protected readonly string         $appSecret,
        ?Client $client = null
    ) {
        if ($client !== null) {
            $this->client = $client;
        }
    }

    // -----------------------------------------------------------------------
    // Abstract — child classes must implement
    // -----------------------------------------------------------------------

    /**
     * Post to the platform using a stored token.
     *
     * Uniform signature for all platform services — the cron controller
     * dispatches to any platform with identical calling convention.
     * Platform-specific context (page ID, account ID, etc.) is passed
     * via the $context array.
     *
     * @return array{success: bool, platform_post_id: string|null, error: string|null}
     */
    abstract public function post(
        array   $scheduledPost,
        string  $token,
        ?string $tokenSecret,
        array   $context = []
    ): array;

    /**
     * Verify that a stored token is still active.
     *
     * Parameter name differs per child class ($pageId, $igUserId) but
     * the type signature is identical — PHP allows renaming parameters
     * in implementations of abstract methods.
     */
    abstract public function verifyToken(string $token, string $platformAccountId): bool;

    // -----------------------------------------------------------------------
    // Concrete public
    // -----------------------------------------------------------------------

    /**
     * Exchange the stored token for a new long-lived token and persist it.
     *
     * Uses the fb_exchange_token grant against GET /oauth/access_token.
     * Applies identically to Facebook Page tokens and Instagram tokens —
     * both are issued by Meta and share the same exchange endpoint.
     *
     * App credentials are read from the connected_platforms row (app_key,
     * app_secret) rather than from constructor-injected values, so this
     * method works correctly regardless of which service instance calls it.
     *
     * On success: writes new token and computed token_expires_at to
     * connected_platforms via a prepared statement.
     *
     * Returns true on successful exchange and persist, false on any failure.
     */
    public function refreshToken(int $connectedPlatformId): bool
    {
        try {
            $tokenData = $this->fetchTokenData($connectedPlatformId);

            if ($tokenData === null || empty($tokenData['access_token'])) {
                return false;
            }

            $appId     = (string) ($tokenData['app_key']    ?? '');
            $appSecret = (string) ($tokenData['app_secret'] ?? '');

            if ($appId === '' || $appSecret === '') {
                return false;
            }

            $response = $this->graphGet('oauth/access_token', [
                'grant_type'        => 'fb_exchange_token',
                'client_id'         => $appId,
                'client_secret'     => $appSecret,
                'fb_exchange_token' => $tokenData['access_token'],
            ]);

            if (empty($response['access_token'])) {
                return false;
            }

            $newToken  = $response['access_token'];
            $expiresAt = isset($response['expires_in'])
                ? date('Y-m-d H:i:s', time() + (int) $response['expires_in'])
                : null;

            $stmt = $this->dbh->prepare(
                'UPDATE connected_platforms
                    SET access_token = ?, token_expires_at = ?, updated_at = NOW()
                  WHERE id = ?'
            );
            $stmt->execute([$newToken, $expiresAt, $connectedPlatformId]);

            return true;

        } catch (Throwable) {
            return false;
        }
    }

    // -----------------------------------------------------------------------
    // OAuth 2.0 connection flow
    // -----------------------------------------------------------------------

    /**
     * Step 1 — Exchange an authorization code for a short-lived user access token.
     *
     * Called in the OAuth callback immediately after Meta redirects back with a code.
     * $redirectUri must exactly match what was passed to the OAuth dialog — Meta
     * validates these against each other.
     *
     * Returns the short-lived user access token string, or null on any failure.
     * This token typically expires in 1–2 hours; call exchangeForLongLivedToken()
     * before storing anything.
     */
    public function exchangeCodeForToken(string $code, string $redirectUri): ?string
    {
        $response = $this->graphGet('oauth/access_token', [
            'client_id'     => $this->appId,
            'client_secret' => $this->appSecret,
            'redirect_uri'  => $redirectUri,
            'code'          => $code,
        ]);

        return !empty($response['access_token']) ? (string) $response['access_token'] : null;
    }

    /**
     * Step 2 — Exchange a short-lived token for a long-lived user access token.
     *
     * Uses the fb_exchange_token grant against GET /oauth/access_token.
     * The long-lived token is valid for ~60 days. Page Access Tokens obtained
     * from a long-lived user token do not expire (no token_expires_at needed).
     *
     * Returns an array with:
     *   access_token  string    The long-lived token
     *   expires_in    int|null  Seconds until expiry, or null if not returned
     *
     * Returns null on any failure.
     *
     * @return array{access_token: string, expires_in: int|null}|null
     */
    public function exchangeForLongLivedToken(string $shortLivedToken): ?array
    {
        $response = $this->graphGet('oauth/access_token', [
            'grant_type'        => 'fb_exchange_token',
            'client_id'         => $this->appId,
            'client_secret'     => $this->appSecret,
            'fb_exchange_token' => $shortLivedToken,
        ]);

        if (empty($response['access_token'])) {
            return null;
        }

        return [
            'access_token' => (string) $response['access_token'],
            'expires_in'   => isset($response['expires_in']) ? (int) $response['expires_in'] : null,
        ];
    }

    /**
     * Step 3 — Discover Facebook Pages and attached Instagram Business accounts.
     *
     * Calls GET /me/accounts with the long-lived user access token.
     * Returns each page's id, name, and access_token, plus any attached
     * instagram_business_account (id, name, username) as a nested key.
     *
     * Page Access Tokens returned here are permanent when the user token is
     * long-lived — store directly in connected_platforms with token_expires_at = NULL.
     *
     * One call covers both Facebook Pages and Instagram Business accounts —
     * no separate Instagram authorization is required.
     *
     * Returns the raw 'data' array from the Graph API response, or an empty
     * array if no pages are found or on any failure.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPagesWithInstagram(string $userAccessToken): array
    {
        $response = $this->graphGet('me/accounts', [
            'fields'       => 'id,name,access_token,instagram_business_account{id,name,username}',
            'access_token' => $userAccessToken,
        ]);

        if (empty($response['data']) || !is_array($response['data'])) {
            return [];
        }

        return $response['data'];
    }

    // -----------------------------------------------------------------------
    // Concrete protected
    // -----------------------------------------------------------------------

    /**
     * Fetch the stored token row for a connected platform.
     *
     * Returns the row as an associative array with at least access_token,
     * token_expires_at, app_key, and app_secret — or null if the row is not
     * found or is inactive.
     *
     * @return array{access_token: string, token_expires_at: string|null, app_key: string|null, app_secret: string|null}|null
     */
    protected function fetchTokenData(int $connectedPlatformId): ?array
    {
        $stmt = $this->dbh->prepare(
            'SELECT access_token, token_expires_at, app_key, app_secret
               FROM connected_platforms
              WHERE id = ? AND is_active = 1
              LIMIT 1'
        );
        $stmt->execute([$connectedPlatformId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Returns true if the token expires within $days days.
     *
     * A null expiry (non-expiring token) always returns false — safe to call
     * for any token without checking the platform type at the call site.
     *
     * Default threshold is TOKEN_REFRESH_THRESHOLD_DAYS (7 days), giving
     * the cron controller a week of buffer before a Meta token goes dead.
     */
    protected function isNearExpiry(
        ?string $tokenExpiresAt,
        int     $days = self::TOKEN_REFRESH_THRESHOLD_DAYS
    ): bool {
        if ($tokenExpiresAt === null) {
            return false;
        }

        $expiresAt = new \DateTimeImmutable($tokenExpiresAt);
        $threshold = new \DateTimeImmutable("+{$days} days");

        return $expiresAt <= $threshold;
    }

    /**
     * Resolve a stored image filename to a public URL for the Graph API.
     *
     * Local driver: StorageService::retrieve() returns an absolute filesystem
     * path — not network-accessible. A public URL is built from BASE_URL.
     * S3 driver: retrieve() returns a public HTTPS URL — used as-is.
     *
     * Both Facebook and Instagram require a publicly accessible image URL.
     */
    protected function resolveImageUrl(string $filename): string
    {
        $path = $this->storage->retrieve($filename);

        if (str_starts_with($path, 'https://') || str_starts_with($path, 'http://')) {
            return $path;
        }

        // Encode each path segment individually to preserve slashes.
        // final_image_filename may be multi-segment (e.g. 'processed/twitter/file.jpg');
        // rawurlencode() on the full string would incorrectly encode the slashes.
        $encoded = implode('/', array_map('rawurlencode', explode('/', $filename)));
        return rtrim(BASE_URL, '/') . '/images/' . $encoded;
    }

    /**
     * Execute a GET request against the Graph API.
     *
     * $endpoint is a path relative to GRAPH_BASE_URI (e.g. 'oauth/access_token',
     * '{pageId}/feed'). $params are sent as query string parameters.
     *
     * Returns the decoded response body. HTTP 4xx/5xx are not thrown —
     * the body is returned so the caller can inspect the error key.
     * Network-level failures bubble to the calling method.
     *
     * @param  array<string, string> $params
     * @return array<string, mixed>
     */
    protected function graphGet(string $endpoint, array $params): array
    {
        $response = $this->client()->get($endpoint, ['query' => $params]);
        $decoded  = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Execute a POST request against the Graph API.
     *
     * $params are sent as an application/x-www-form-urlencoded body.
     * Both Facebook (/{pageId}/feed, /{pageId}/photos) and Instagram
     * (/{igUserId}/media, /{igUserId}/media_publish) accept form-encoded
     * bodies — no JSON body variant is needed.
     *
     * Returns the decoded response body. HTTP 4xx/5xx are not thrown.
     * Network-level failures bubble to the calling method.
     *
     * @param  array<string, string> $params
     * @return array<string, mixed>
     */
    protected function graphPost(string $endpoint, array $params): array
    {
        $response = $this->client()->post($endpoint, ['form_params' => $params]);
        $decoded  = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Extract a clean error string from a raw Graph API response body string.
     *
     * Used when a string body is available rather than a decoded array —
     * typically from a GuzzleException response or an unexpected non-JSON body.
     * For decoded arrays use parsePostResponse() instead.
     */
    protected function extractError(string $responseBody): string
    {
        $decoded = json_decode($responseBody, true);

        if (isset($decoded['error']['message'])) {
            return (string) $decoded['error']['message'];
        }

        return 'Unknown Graph API error.';
    }

    /**
     * Normalise a decoded Graph API response into the standard return shape.
     *
     * Successful Graph API create operations return { "id": "..." }.
     * The id field is the platform-assigned identifier for the created object
     * (post ID, media container ID, published media ID).
     *
     * An error key in the response body means the API rejected the request
     * (invalid token, rate limit, policy violation, etc.).
     *
     * @param  array<string, mixed> $response
     * @return array{success: bool, platform_post_id: string|null, error: string|null}
     */
    protected function parsePostResponse(array $response): array
    {
        if (isset($response['error'])) {
            $msg = $response['error']['message'] ?? 'Unknown Graph API error.';
            return ['success' => false, 'platform_post_id' => null, 'error' => (string) $msg];
        }

        if (isset($response['id'])) {
            return ['success' => true, 'platform_post_id' => (string) $response['id'], 'error' => null];
        }

        return [
            'success'          => false,
            'platform_post_id' => null,
            'error'            => 'Graph API returned no id and no error.',
        ];
    }

    // -----------------------------------------------------------------------
    // Private
    // -----------------------------------------------------------------------

    /**
     * Lazy Guzzle client getter.
     *
     * Built once on first call and cached. base_uri trailing slash is required
     * for Guzzle to resolve relative endpoint paths correctly — without it,
     * a path like '{pageId}/feed' would drop the v19.0 segment.
     *
     * http_errors => false: Graph API errors are in the response body.
     * Guzzle must not throw on 4xx/5xx so we can decode and inspect them.
     */
    private function client(): Client
    {
        if ($this->client === null) {
            $this->client = new Client([
                'base_uri'    => self::GRAPH_BASE_URI . '/',
                'timeout'     => 15,
                'http_errors' => false,
            ]);
        }

        return $this->client;
    }
}
