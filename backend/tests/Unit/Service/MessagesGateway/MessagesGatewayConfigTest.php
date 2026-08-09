<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MessagesGateway;

use App\Repository\ConfigRepository;
use App\Service\MessagesGateway\MessagesGatewayConfig;
use App\Service\Security\SsrfGuard;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class MessagesGatewayConfigTest extends TestCase
{
    private ConfigRepository&MockObject $configRepository;
    private SsrfGuard&MockObject $ssrfGuard;
    private MessagesGatewayConfig $config;

    protected function setUp(): void
    {
        $this->configRepository = $this->createMock(ConfigRepository::class);
        $this->ssrfGuard = $this->createMock(SsrfGuard::class);
        $this->config = new MessagesGatewayConfig(
            $this->configRepository,
            $this->ssrfGuard,
            $this->createMock(LoggerInterface::class),
            '',
        );
    }

    public function testDefaultsWhenNoRows(): void
    {
        $this->configRepository->method('getValue')->willReturn(null);

        $this->assertFalse($this->config->isEnabled(1));
        $this->assertFalse($this->config->allowOperatorKey(1));
        $this->assertTrue($this->config->isBudgetNoticeEnabled(1));
        $this->assertSame(MessagesGatewayConfig::WEB_SEARCH_AUTO, $this->config->webSearchMode(1));
        $this->assertSame(MessagesGatewayConfig::VISION_AUTO, $this->config->visionMode(1));
        $this->assertSame(MessagesGatewayConfig::DEFAULT_UPSTREAM_URL, $this->config->upstreamUrl());
        $this->assertSame([], $this->config->modelAliases());
    }

    public function testUpstreamUrlPrefersDbOverEnv(): void
    {
        $this->config = new MessagesGatewayConfig(
            $this->configRepository,
            $this->ssrfGuard,
            $this->createMock(LoggerInterface::class),
            'http://127.0.0.1:8099',
        );

        $this->configRepository->method('getValue')
            ->willReturnCallback(static function (int $ownerId, string $group, string $setting): ?string {
                if (0 === $ownerId && 'UPSTREAM_URL' === $setting) {
                    return 'https://api.anthropic.com';
                }

                return null;
            });

        $this->assertSame('https://api.anthropic.com', $this->config->upstreamUrl());
    }

    public function testUpstreamUrlFallsBackToEnv(): void
    {
        $this->config = new MessagesGatewayConfig(
            $this->configRepository,
            $this->ssrfGuard,
            $this->createMock(LoggerInterface::class),
            'http://host.docker.internal:8099/',
        );
        $this->configRepository->method('getValue')->willReturn(null);

        $this->assertSame('http://host.docker.internal:8099', $this->config->upstreamUrl());
    }

    public function testValidateUpstreamUrlRejectsCredentials(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->config->validateUpstreamUrl('https://user:pass@api.anthropic.com');
    }

    public function testValidateUpstreamUrlAllowsHttpsPublic(): void
    {
        $this->ssrfGuard->method('isBlockedUrl')->willReturn(false);

        $normalized = $this->config->validateUpstreamUrl('https://api.anthropic.com/');
        $this->assertSame('https://api.anthropic.com', $normalized);
    }

    public function testValidateUpstreamUrlAllowsHttpLoopback(): void
    {
        $normalized = $this->config->validateUpstreamUrl('http://127.0.0.1:8099');
        $this->assertSame('http://127.0.0.1:8099', $normalized);
    }

    public function testValidateUpstreamUrlRejectsHttpPublic(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->config->validateUpstreamUrl('http://evil.example.com');
    }

    public function testPerUserEnabledOverridesGlobal(): void
    {
        $this->configRepository->method('getValue')
            ->willReturnCallback(static function (int $ownerId, string $group, string $setting): ?string {
                if ('ENABLED' !== $setting) {
                    return null;
                }

                return match ($ownerId) {
                    5 => '1',
                    0 => '0',
                    default => null,
                };
            });

        $this->assertTrue($this->config->isEnabled(5));
        $this->assertFalse($this->config->isEnabled(99));
    }
}
