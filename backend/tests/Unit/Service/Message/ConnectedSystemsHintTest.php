<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message;

use App\Entity\McpServerConfig;
use App\Repository\McpServerConfigRepository;
use App\Service\Mcp\McpClientConfig;
use App\Service\Mcp\McpToolRegistry;
use App\Service\Message\ConnectedSystemsHint;
use App\Service\Multitask\MultitaskRoutingConfig;
use PHPUnit\Framework\TestCase;

final class ConnectedSystemsHintTest extends TestCase
{
    private function server(string $name): McpServerConfig
    {
        $server = $this->createMock(McpServerConfig::class);
        $server->method('getName')->willReturn($name);

        return $server;
    }

    /**
     * @param list<McpServerConfig>          $servers
     * @param list<array{name: string}>|null $cachedTools
     */
    private function hint(array $servers, bool $clientOn = true, bool $fetchOn = true, ?array $cachedTools = null): ConnectedSystemsHint
    {
        $repo = $this->createMock(McpServerConfigRepository::class);
        $repo->method('findEnabledByUser')->willReturn($servers);
        $client = $this->createMock(McpClientConfig::class);
        $client->method('isClientEnabled')->willReturn($clientOn);
        $routing = $this->createMock(MultitaskRoutingConfig::class);
        $routing->method('isFeatureEnabled')->willReturn($fetchOn);
        $registry = $this->createMock(McpToolRegistry::class);
        $registry->expects(self::never())->method('toolsFor'); // never a network round-trip
        $registry->method('cachedToolsFor')->willReturn($cachedTools);

        return new ConnectedSystemsHint($repo, $client, $routing, $registry);
    }

    public function testNamesTheConnectionAndItsCachedTools(): void
    {
        $tools = array_map(static fn (string $n): array => ['name' => $n, 'description' => '', 'inputSchema' => [], 'annotations' => []], [
            'b2_list_buckets', 's3_head_bucket', 's3_get_bucket_location', 's3_list_objects_v2',
            's3_head_object', 's3_get_object', 's3_list_object_versions', 'b2_get_bucket_notification_rules', 's3_put_object',
        ]);

        $block = $this->hint([$this->server('Backblaze B2')], cachedTools: $tools)->renderForSorter(2);

        self::assertStringContainsString('## Connected systems of this user', $block);
        self::assertStringContainsString('set BMULTI to 1', $block);
        self::assertStringContainsString('- "Backblaze B2" (connected data source) — tools: b2_list_buckets, s3_head_bucket, s3_get_bucket_location', $block);
        self::assertStringContainsString(', …', $block, 'more than eight tools are elided');
        self::assertStringNotContainsString('s3_put_object', $block);
    }

    public function testNamesOnlyWhenNothingIsCachedYet(): void
    {
        $block = $this->hint([$this->server('Company CRM')])->renderForSorter(2);

        self::assertStringContainsString('- "Company CRM" (connected data source)', $block);
        self::assertStringNotContainsString('tools:', $block);
    }

    public function testEmptyWithoutConnectionsOrForAnonymousUsers(): void
    {
        self::assertSame('', $this->hint([])->renderForSorter(2));
        self::assertSame('', $this->hint([$this->server('X')])->renderForSorter(null));
    }

    public function testRespectsTheSameGatesAsThePlannerSubCatalog(): void
    {
        self::assertSame('', $this->hint([$this->server('X')], clientOn: false)->renderForSorter(2));
        self::assertSame('', $this->hint([$this->server('X')], fetchOn: false)->renderForSorter(2));
    }
}
