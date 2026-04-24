<?php
declare(strict_types=1);

namespace SocialTurn\Services;

use Throwable;

/**
 * InstagramService
 *
 * Posts to an Instagram Business account using Graph API v19+.
 * Instagram Business accounts are connected through a Facebook Page —
 * the token used here is the Page Access Token stored in connected_platforms,
 * not a separate Instagram-specific credential.
 *
 * Extends AbstractMetaService for shared Graph API infrastructure:
 * Guzzle client, token refresh, image URL resolution, and response parsing.
 *
 * Token lifecycle: identical to Facebook — ~60-day expiry, same exchange
 * endpoint, same refreshToken() implementation (inherited).
 *
 * Posting flow — two-step container pattern:
 *   Step 1: POST /{igUserId}/media        — creates a media container, returns creation_id
 *   Step 2: POST /{igUserId}/media_publish — publishes the container, returns post id
 *
 * Instagram requires an image for every post. The caption is attached in
 * Step 1 with the container — not in Step 2. Text-only posts are not
 * supported by the Instagram Graph API; post() returns an error immediately
 * if image_filename is null, without making any API call.
 *
 * Both /{igUserId}/media and /{igUserId}/media_publish accept
 * application/x-www-form-urlencoded bodies — form_params is correct,
 * no JSON body variant is needed.
 *
 * $context keys required by post():
 *   ig_user_id               string  Instagram Business account ID (platform_account_id)
 *   connected_platform_id    int     Row ID in connected_platforms
 */
class InstagramService extends AbstractMetaService
{
    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Post to an Instagram Business account via Graph API v19+.
     *
     * Two-step container pattern:
     *   Step 1: createMediaContainer() → creation_id
     *   Step 2: publishMediaContainer() → published post id
     *
     * $scheduledPost must contain:
     *   final_body  string  Pre-rendered post text with tags appended (caption)
     *
     * $token       Page Access Token (from connected_platforms.access_token)
     * $tokenSecret Ignored — Instagram via Graph API does not use OAuth 1.0a secrets.
     * $context     Must contain ig_user_id (string), connected_platform_id (int),
     *              and images (list<string>) — processed filenames from storage.
     *              Instagram requires at least one image; text-only posts are not supported.
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

        $igUserId            = $context['ig_user_id']            ?? null;
        $connectedPlatformId = $context['connected_platform_id'] ?? null;

        if ($igUserId === null || $connectedPlatformId === null) {
            $result['error'] = 'InstagramService::post() requires ig_user_id and connected_platform_id in $context.';
            return $result;
        }

        $images = $context['images'] ?? [];

        if (empty($images)) {
            $result['error'] = 'Instagram does not support text-only posts — at least one image is required.';
            return $result;
        }

        try {
            if (count($images) === 1) {
                $imageUrl   = $this->resolveImageUrl($images[0]);
                $creationId = $this->createMediaContainer(
                    (string) $igUserId,
                    $token,
                    $imageUrl,
                    $scheduledPost['final_body']
                );

                if ($creationId === null) {
                    $result['error'] = 'Instagram media container creation failed — check image URL, token, and account permissions.';
                    return $result;
                }

                $result = $this->publishMediaContainer((string) $igUserId, $token, $creationId);
            } else {
                $result = $this->postCarousel(
                    (string) $igUserId,
                    $token,
                    $scheduledPost['final_body'],
                    $images
                );
            }

        } catch (Throwable $e) {
            $result['error'] = 'InstagramService error: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * Verify that a stored token is still active for this Instagram account.
     *
     * Calls GET /{igUserId}?fields=id,name — a lightweight read that confirms
     * the token is valid and the account is accessible without side effects.
     *
     * Returns true if the API returns an id field, false on any error.
     */
    public function verifyToken(string $token, string $platformAccountId): bool
    {
        try {
            $response = $this->graphGet(rawurlencode($platformAccountId), [
                'fields'       => 'id,name',
                'access_token' => $token,
            ]);

            return isset($response['id']) && !isset($response['error']);
        } catch (Throwable) {
            return false;
        }
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Step 1 — Create an Instagram media container.
     *
     * POST /{igUserId}/media
     *   image_url={public_url}
     *   caption={text}
     *   access_token={token}
     *
     * The caption (post text) is attached here, not at publish time.
     * The API returns { "id": "creation_id" } on success.
     *
     * Returns the creation_id string on success, null on any failure
     * (API error, missing id in response, network error).
     */
    private function createMediaContainer(
        string $igUserId,
        string $token,
        string $imageUrl,
        string $caption
    ): ?string {
        $response = $this->graphPost(rawurlencode($igUserId) . '/media', [
            'image_url'    => $imageUrl,
            'caption'      => $caption,
            'access_token' => $token,
        ]);

        if (isset($response['error']) || empty($response['id'])) {
            return null;
        }

        return (string) $response['id'];
    }

    /**
     * Publish a carousel post to Instagram.
     *
     * Three-step pattern:
     *   Step 1: Create one carousel item container per image via POST /{igUserId}/media
     *           with is_carousel_item=true — no caption at this stage.
     *   Step 2: Create the carousel container via POST /{igUserId}/media with
     *           media_type=CAROUSEL, comma-separated children IDs, and the caption.
     *   Step 3: Publish the carousel container via publishMediaContainer().
     *
     * resolveImageUrl() (inherited) handles local vs S3 driver differences.
     *
     * @param  list<string> $images  Processed image filenames from storage (2–4 items)
     * @return array{success: bool, platform_post_id: string|null, error: string|null}
     */
    private function postCarousel(string $igUserId, string $token, string $caption, array $images): array
    {
        $childIds = [];
        foreach ($images as $filename) {
            $response = $this->graphPost(rawurlencode($igUserId) . '/media', [
                'image_url'        => $this->resolveImageUrl($filename),
                'is_carousel_item' => 'true',
                'access_token'     => $token,
            ]);
            if (isset($response['error']) || empty($response['id'])) {
                $msg = $response['error']['message'] ?? 'Unknown Graph API error.';
                return ['success' => false, 'platform_post_id' => null, 'error' => (string) $msg];
            }
            $childIds[] = (string) $response['id'];
        }

        $response = $this->graphPost(rawurlencode($igUserId) . '/media', [
            'media_type'   => 'CAROUSEL',
            'children'     => implode(',', $childIds),
            'caption'      => $caption,
            'access_token' => $token,
        ]);
        if (isset($response['error']) || empty($response['id'])) {
            $msg = $response['error']['message'] ?? 'Unknown Graph API error.';
            return ['success' => false, 'platform_post_id' => null, 'error' => (string) $msg];
        }

        return $this->publishMediaContainer($igUserId, $token, (string) $response['id']);
    }

    /**
     * Step 2 — Publish a previously created media container.
     *
     * POST /{igUserId}/media_publish
     *   creation_id={creation_id}
     *   access_token={token}
     *
     * The API returns { "id": "ig_media_id" } on success — this is the
     * permanent Instagram post ID stored in post_history as platform_post_id.
     *
     * @return array{success: bool, platform_post_id: string|null, error: string|null}
     */
    private function publishMediaContainer(string $igUserId, string $token, string $creationId): array
    {
        $response = $this->graphPost(rawurlencode($igUserId) . '/media_publish', [
            'creation_id'  => $creationId,
            'access_token' => $token,
        ]);

        return $this->parsePostResponse($response);
    }
}
