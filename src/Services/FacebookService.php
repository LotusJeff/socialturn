<?php
declare(strict_types=1);

namespace SocialTurn\Services;

use Throwable;

/**
 * FacebookService
 *
 * Posts to a Facebook Page using Graph API v19+.
 * Authenticates via a Page Access Token stored in connected_platforms.
 *
 * Extends AbstractMetaService for shared Graph API infrastructure:
 * Guzzle client, token refresh, image URL resolution, and response parsing.
 *
 * Token lifecycle: Page Access Tokens expire in ~60 days. refreshToken()
 * (inherited) handles the fb_exchange_token exchange automatically.
 *
 * Text-only and image posts are both supported. Instagram does not support
 * text-only posts — this distinction exists only in FacebookService.
 *
 * $context keys required by post():
 *   page_id                  string  Facebook Page ID (platform_account_id)
 *   connected_platform_id    int     Row ID in connected_platforms
 */
class FacebookService extends AbstractMetaService
{
    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Post to a Facebook Page via Graph API v19+.
     *
     * Text-only post: POST /{page_id}/feed
     * Image post:     POST /{page_id}/photos  (publishes immediately)
     *
     * $scheduledPost must contain:
     *   final_body          string       Pre-rendered post text with tags appended
     *   image_filename      string|null  Filename in managed storage, or null for text-only
     *
     * $token       Page Access Token (from connected_platforms.access_token)
     * $tokenSecret Ignored — Facebook does not use OAuth 1.0a secrets.
     * $context     Must contain page_id (string) and connected_platform_id (int).
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

        $pageId              = $context['page_id']               ?? null;
        $connectedPlatformId = $context['connected_platform_id'] ?? null;

        if ($pageId === null || $connectedPlatformId === null) {
            $result['error'] = 'FacebookService::post() requires page_id and connected_platform_id in $context.';
            return $result;
        }

        try {
            if (!empty($scheduledPost['final_image_filename'])) {
                $result = $this->postPhoto(
                    (string) $pageId,
                    $token,
                    $scheduledPost['final_body'],
                    $scheduledPost['final_image_filename']
                );
            } else {
                $result = $this->postText(
                    (string) $pageId,
                    $token,
                    $scheduledPost['final_body']
                );
            }
        } catch (Throwable $e) {
            $result['error'] = 'FacebookService error: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * Verify that a stored Page Access Token is still active.
     *
     * Calls GET /{page_id}/feed?limit=1&fields=id — a lightweight read that
     * fails fast on an invalid or expired token without touching post data.
     *
     * Returns true if the API responds without an error node, false otherwise.
     */
    public function verifyToken(string $token, string $platformAccountId): bool
    {
        try {
            $response = $this->graphGet(rawurlencode($platformAccountId) . '/feed', [
                'limit'        => '1',
                'fields'       => 'id',
                'access_token' => $token,
            ]);

            return !isset($response['error']);
        } catch (Throwable) {
            return false;
        }
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Publish a text-only post to a Facebook Page feed.
     *
     * POST /{page_id}/feed
     *   message={text}
     *   access_token={token}
     *
     * @return array{success: bool, platform_post_id: string|null, error: string|null}
     */
    private function postText(string $pageId, string $token, string $body): array
    {
        $response = $this->graphPost(rawurlencode($pageId) . '/feed', [
            'message'      => $body,
            'access_token' => $token,
        ]);

        return $this->parsePostResponse($response);
    }

    /**
     * Publish an image post to a Facebook Page.
     *
     * POST /{page_id}/photos
     *   url={public_image_url}
     *   caption={text}
     *   access_token={token}
     *
     * resolveImageUrl() (inherited) handles local vs S3 driver differences.
     *
     * @return array{success: bool, platform_post_id: string|null, error: string|null}
     */
    private function postPhoto(string $pageId, string $token, string $body, string $filename): array
    {
        $response = $this->graphPost(rawurlencode($pageId) . '/photos', [
            'url'          => $this->resolveImageUrl($filename),
            'caption'      => $body,
            'access_token' => $token,
        ]);

        return $this->parsePostResponse($response);
    }
}
