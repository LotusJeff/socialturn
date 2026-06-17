<?php
declare(strict_types=1);

namespace SocialTurn\Services;

use Abraham\TwitterOAuth\TwitterOAuth;
use Throwable;

/**
 * TwitterService
 *
 * Posts to Twitter/X using API v2.
 * Authenticates via OAuth 1.0a using per-account tokens stored in
 * connected_platforms. App-level credentials (Consumer Key/Secret) are injected
 * via the constructor and stored per connected_platforms row.
 *
 * Token lifecycle: OAuth 1.0a tokens do not expire. refreshToken() always
 * returns true without making any API call.
 *
 * Image posts: Twitter has not migrated media upload to v2. Image posts upload
 * the file via the v1.1 media/upload endpoint, then attach the returned
 * media_id to the v2 tweet payload. This is an official Twitter API limitation.
 */
class TwitterService
{
    /**
     * Cached TwitterOAuth clients keyed by access token string.
     * A single cron run may post multiple items through the same Twitter account.
     * The client is built once per token and reused for all subsequent calls
     * with the same token rather than being rebuilt on each invocation.
     *
     * @var array<string, TwitterOAuth>
     */
    private array $clients = [];

    public function __construct(
        private readonly StorageService $storage,
        private readonly string         $appKey,
        private readonly string         $appSecret,
    ) {}

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Post to Twitter/X using API v2.
     *
     * Text-only post: POST https://api.twitter.com/2/tweets
     * Image post:     Upload media via v1.1 media/upload (no v2 equivalent exists),
     *                 then attach the returned media_id_string to the v2 payload.
     *
     * $scheduledPost must contain:
     *   final_body  string  Pre-rendered post text with tags appended
     *
     * $token       OAuth 1.0a access token (from connected_platforms.access_token)
     * $tokenSecret OAuth 1.0a token secret (from connected_platforms.token_secret)
     *              Required for Twitter — a null value returns an immediate error.
     * $context     images: list<string>  Processed image filenames from storage; empty = text-only.
     *
     * @return array{success: bool, platform_post_id: string|null, error: string|null}
     */
    public function post(array $scheduledPost, string $token, ?string $tokenSecret, array $context = []): array
    {
        $result = [
            'success'          => false,
            'platform_post_id' => null,
            'error'            => null,
        ];

        if ($tokenSecret === null) {
            $result['error'] = 'Twitter requires a token secret (OAuth 1.0a) — token_secret is null.';
            return $result;
        }

        try {
            $client  = $this->client($token, $tokenSecret);
            $payload = ['text' => $scheduledPost['final_body']];
            $images  = $context['images'] ?? [];

            if (!empty($images)) {
                $mediaIds = [];
                foreach ($images as $filename) {
                    $mediaResult = $this->uploadMedia($client, $filename);
                    if (isset($mediaResult['error'])) {
                        $result['error'] = 'Media upload to Twitter failed — ' . $mediaResult['error'];
                        return $result;
                    }
                    $mediaIds[] = $mediaResult['media_id'];
                }
                $payload['media'] = ['media_ids' => $mediaIds];
            }

            // Third argument true = send payload as JSON, required for v2
            $response = $client->post('tweets', $payload, true);

            if (isset($response->data->id)) {
                $result['success']          = true;
                $result['platform_post_id'] = (string) $response->data->id;
            } else {
                $result['error'] = $this->extractError($response);
            }

        } catch (Throwable $e) {
            $result['error'] = 'TwitterService error: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * Verify that a stored token pair is still active.
     * Calls GET https://api.twitter.com/2/users/me
     * Returns true if a valid user ID is returned, false on any error or 401.
     */
    public function verifyToken(string $token, ?string $tokenSecret): bool
    {
        if ($tokenSecret === null) {
            return false;
        }

        try {
            $response = $this->client($token, $tokenSecret)->get('users/me');
            return isset($response->data->id);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Twitter OAuth 1.0a tokens do not expire and cannot be refreshed via API.
     * This method exists to satisfy the platform service contract and always
     * returns true without making any API call.
     */
    public function refreshToken(int $connectedPlatformId): bool
    {
        return true;
    }

    // -----------------------------------------------------------------------
    // OAuth 1.0a connection flow
    // -----------------------------------------------------------------------

    /**
     * Step 1 — Get a request token from Twitter.
     *
     * Called before the user is redirected to Twitter for authorization.
     * Creates a TwitterOAuth client with app credentials only (no user token yet).
     * $callbackUrl must exactly match the URL registered in the Twitter Developer Portal.
     *
     * Returns an array with at minimum:
     *   oauth_token        string  Passed to getAuthorizeUrl() and stored in SESSION
     *   oauth_token_secret string  Stored in SESSION for use in getAccessToken()
     *   oauth_callback_confirmed string  'true' on success
     *
     * Returns an empty array on any failure — callers must check before redirecting.
     *
     * @return array<string, string>
     */
    public function getRequestToken(string $callbackUrl): array
    {
        $connection = new TwitterOAuth($this->appKey, $this->appSecret);
        $result     = $connection->oauth('oauth/request_token', ['oauth_callback' => $callbackUrl]);

        return is_array($result) ? $result : [];
    }

    /**
     * Step 2 — Build the Twitter authorization URL.
     *
     * Redirects the user here so they can authorize the app.
     * $requestToken is the oauth_token returned by getRequestToken().
     *
     * Returns the full https://api.twitter.com/oauth/authorize?oauth_token=... URL.
     */
    public function getAuthorizeUrl(string $requestToken): string
    {
        $connection = new TwitterOAuth($this->appKey, $this->appSecret);

        return $connection->url('oauth/authorize', ['oauth_token' => $requestToken]);
    }

    /**
     * Step 3 — Exchange verifier for a permanent access token pair.
     *
     * Called in the OAuth callback after Twitter redirects back with
     * oauth_token and oauth_verifier. $requestToken and $requestTokenSecret
     * are the values stored in SESSION during Step 1.
     *
     * Returns an array with at minimum:
     *   oauth_token        string  Permanent access token — store in connected_platforms
     *   oauth_token_secret string  Permanent token secret — store in connected_platforms
     *   user_id            string  Twitter user ID — use as platform_account_id
     *   screen_name        string  Twitter handle — use as platform_username
     *
     * Returns an empty array on any failure.
     *
     * @return array<string, string>
     */
    public function getAccessToken(
        string $requestToken,
        string $requestTokenSecret,
        string $verifier
    ): array {
        $connection = new TwitterOAuth(
            $this->appKey,
            $this->appSecret,
            $requestToken,
            $requestTokenSecret
        );
        $result = $connection->oauth('oauth/access_token', ['oauth_verifier' => $verifier]);

        return is_array($result) ? $result : [];
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Returns a configured TwitterOAuth client for the given token pair.
     *
     * Clients are cached in $this->clients keyed by access token string.
     * A single cron run may post multiple items through the same Twitter account —
     * the client is built once and reused for all calls with the same token
     * rather than being reconstructed on each invocation.
     *
     * Uses $this->appKey and $this->appSecret (per-connection credentials).
     * Sets API version to v2 on construction; uploadMedia() temporarily
     * switches to v1.1 and restores v2 in a finally block.
     */
    protected function client(string $token, string $tokenSecret): TwitterOAuth
    {
        if (!isset($this->clients[$token])) {
            $client = new TwitterOAuth(
                $this->appKey,
                $this->appSecret,
                $token,
                $tokenSecret
            );
            $client->setApiVersion('2');
            $this->clients[$token] = $client;
        }

        return $this->clients[$token];
    }

    /**
     * Uploads a media file to Twitter via the v1.1 media/upload endpoint.
     *
     * Streams the file from StorageService::getReadStream() into a temporary
     * file using stream_copy_to_stream() — the full file is never loaded into
     * memory at once. The temp file is passed to the upload endpoint as a
     * CURLFile and deleted in the finally block regardless of outcome.
     *
     * Media upload lives on v1.1 — Twitter has not migrated this endpoint to v2.
     * The client's API version is temporarily set to v1.1 and always restored
     * to v2 in the finally block, even if an exception occurs.
     *
     * Returns media_id_string on success, null on any failure.
     */
    private function uploadMedia(TwitterOAuth $client, string $filename): array
    {
        $tmpFile = null;

        try {
            $stream    = $this->storage->getReadStream($filename);
            $tmpFile   = tempnam(sys_get_temp_dir(), 'socialturn_tw_');
            $tmpStream = fopen($tmpFile, 'wb');
            stream_copy_to_stream($stream, $tmpStream);
            fclose($tmpStream);
            fclose($stream);

            // Media upload endpoint is v1.1 only — no v2 equivalent exists
            $client->setApiVersion('1.1');
            $response = $client->upload('media/upload', ['media' => $tmpFile]);

            if (!isset($response->media_id_string)) {
                return ['error' => 'Twitter API did not return media_id_string. Response: ' . json_encode($response)];
            }
            return ['media_id' => (string) $response->media_id_string];

        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        } finally {
            // Always restore v2 on the cached client and clean up the temp file
            $client->setApiVersion('2');
            if ($tmpFile !== null && file_exists($tmpFile)) {
                @unlink($tmpFile);
            }
        }
    }

    /**
     * Extracts a clean error string from a TwitterOAuth response.
     * Handles v2-style (detail, errors[].detail, title) and
     * v1.1-style (errors[].message) response shapes.
     */
    private function extractError(mixed $response): string
    {
        // v2: top-level detail field
        if (isset($response->detail)) {
            return (string) $response->detail;
        }

        // v2: errors array with detail per error
        if (isset($response->errors[0]->detail)) {
            return (string) $response->errors[0]->detail;
        }

        // v2: title fallback (e.g. "Unauthorized", "Forbidden")
        if (isset($response->title)) {
            return (string) $response->title;
        }

        // v1.1: errors array with message per error
        if (isset($response->errors[0]->message)) {
            return (string) $response->errors[0]->message;
        }

        return 'Unknown Twitter API error.';
    }
}
