<?php
declare(strict_types=1);

namespace Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use PDO;
use PHPUnit\Framework\TestCase;
use SocialTurn\Services\InstagramService;
use SocialTurn\Services\StorageService;

/**
 * Unit tests for InstagramService.
 *
 * AbstractMetaService accepts an optional ?Client $client = null constructor
 * argument — the supported injection point for mocking Graph API calls.
 * StorageService::retrieve() is mocked to return an https:// URL, which
 * resolveImageUrl() uses as-is, bypassing the BASE_URL constant.
 */
class InstagramServiceTest extends TestCase
{
    // TC1 — Graph API error from createMediaContainer() propagates verbatim
    //        through post() rather than being replaced by a static fallback string
    public function testCreateMediaContainerApiErrorPropagatedToPostResult(): void
    {
        $apiErrorMessage = 'Invalid OAuth access token.';

        $mockClient = $this->createMock(Client::class);
        $mockClient
            ->method('post')
            ->willReturn(new Response(200, [], (string) json_encode([
                'error' => [
                    'message' => $apiErrorMessage,
                    'type'    => 'OAuthException',
                    'code'    => 190,
                ],
            ])));

        $mockStorage = $this->createMock(StorageService::class);
        // Return an https:// URL — resolveImageUrl() passes it straight through
        // without needing BASE_URL, keeping this test free of config constants.
        $mockStorage->method('retrieve')->willReturn('https://cdn.example.com/image.jpg');

        $service = new InstagramService(
            $this->createMock(PDO::class),
            $mockStorage,
            'fake_app_id',
            'fake_app_secret',
            $mockClient
        );

        $result = $service->post(
            ['final_body' => 'Test caption'],
            'fake_token',
            null,
            [
                'ig_user_id'            => '123456789',
                'connected_platform_id' => 1,
                'images'                => ['img.jpg'],
            ]
        );

        $this->assertFalse($result['success']);
        $this->assertNull($result['platform_post_id']);
        $this->assertSame($apiErrorMessage, $result['error']);
    }
}
