<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mcp;

use App\Repository\ConfigRepository;
use App\Service\Mcp\McpClientConfig;
use PHPUnit\Framework\TestCase;

final class McpClientConfigTest extends TestCase
{
    public function testOAuthConnectorsDefaultOffAndSurviveAnOperatorOverride(): void
    {
        $repo = $this->createMock(ConfigRepository::class);
        $repo->method('getValue')->willReturn(null);

        self::assertFalse((new McpClientConfig($repo))->isOAuthConnectorsEnabled());

        $on = $this->createMock(ConfigRepository::class);
        $on->method('getValue')->willReturnCallback(
            static fn (int $owner, string $group, string $key): ?string => 0 === $owner && 'OAUTH_CONNECTORS_ENABLED' === $key ? '1' : null
        );
        self::assertTrue((new McpClientConfig($on))->isOAuthConnectorsEnabled());

        $off = $this->createMock(ConfigRepository::class);
        $off->method('getValue')->willReturnCallback(
            static fn (int $owner, string $group, string $key): ?string => 0 === $owner && 'OAUTH_CONNECTORS_ENABLED' === $key ? '0' : null
        );
        self::assertFalse((new McpClientConfig($off))->isOAuthConnectorsEnabled());
    }
}
