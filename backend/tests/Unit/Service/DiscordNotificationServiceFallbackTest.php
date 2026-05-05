<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Repository\UserRepository;
use App\Service\DiscordNotificationService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Regression tests for the embedding-fallback Discord alert.
 *
 * These tests pin the wire format of the webhook POST so future refactors
 * cannot silently drop the `@everyone` ping or the
 * `allowed_mentions.parse: ['everyone']` flag — without both, Discord
 * does not actually notify the channel.
 */
final class DiscordNotificationServiceFallbackTest extends TestCase
{
    private HttpClientInterface&MockObject $httpClient;
    private LoggerInterface&MockObject $logger;
    private UserRepository&MockObject $userRepository;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->userRepository = $this->createMock(UserRepository::class);
    }

    #[Test]
    public function notifyEmbeddingFallbackPingsEveryoneWithAllowedMentions(): void
    {
        $captured = null;

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://discord.example/webhook',
                $this->callback(function (array $options) use (&$captured): bool {
                    $captured = $options['json'] ?? null;

                    return true;
                })
            )
            ->willReturn($this->createMock(ResponseInterface::class));

        $service = new DiscordNotificationService(
            $this->httpClient,
            $this->logger,
            $this->userRepository,
            'https://discord.example/webhook',
        );

        $service->notifyEmbeddingFallback('ollama', 'cloudflare', 'Connection refused');

        $this->assertIsArray($captured);
        $this->assertArrayHasKey('content', $captured, 'Discord requires a top-level "content" field for @everyone to fire');
        $this->assertSame('@everyone', $captured['content']);

        $this->assertArrayHasKey('allowed_mentions', $captured, 'Webhooks strip @everyone unless allowed_mentions opts in');
        $this->assertSame(['parse' => ['everyone']], $captured['allowed_mentions']);

        $this->assertArrayHasKey('embeds', $captured);
        $this->assertCount(1, $captured['embeds']);
        $embed = $captured['embeds'][0];
        $this->assertStringContainsString('[INCIDENT]', $embed['title']);

        // Both providers must be visible at a glance for on-call to triage.
        $names = array_column($embed['fields'], 'name');
        $this->assertContains('Primary Provider (DOWN)', $names);
        $this->assertContains('Fallback Provider (active)', $names);
    }

    #[Test]
    public function notifyEmbeddingFallbackIsNoOpWhenWebhookNotConfigured(): void
    {
        $this->httpClient
            ->expects($this->never())
            ->method('request');

        $service = new DiscordNotificationService(
            $this->httpClient,
            $this->logger,
            $this->userRepository,
            null,
        );

        $service->notifyEmbeddingFallback('ollama', 'cloudflare', 'Connection refused');
    }
}
