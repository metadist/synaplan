<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Tool\Source;

use App\AI\Messages\Mcp\McpToolCatalogAdapter;
use App\Entity\McpServerConfig;
use App\Service\Mcp\McpToolRegistry;
use App\Service\Mcp\Permissions\McpToolPermissions;
use App\Service\Tool\Policy\McpToolMeta;
use App\Service\Tool\SideEffect;
use App\Service\Tool\Source\McpClientToolSource;
use PHPUnit\Framework\TestCase;

final class McpClientToolSourceTest extends TestCase
{
    public function testASharedConnectionPublishesReadsAndHidesWrites(): void
    {
        $tools = $this->describe(shared: [$this->tool('search', true), $this->tool('update', false)]);

        self::assertCount(1, $tools);
        self::assertSame('mcp:9:search', $tools[0]->name);
        self::assertSame(SideEffect::Read, $tools[0]->sideEffect);
        self::assertSame('auto', $tools[0]->meta[McpToolMeta::CONNECTION_OUTCOME]);
        self::assertTrue($tools[0]->meta['shared']);
    }

    public function testASharedMemberNeverSeesAToolTheOwnerBlocked(): void
    {
        $tools = $this->describe(shared: [$this->tool('search', true), $this->tool('fetch', true)], policy: '{"v":1,"tools":{"search":"block"}}');

        self::assertSame(['mcp:9:fetch'], array_map(static fn ($tool): string => $tool->name, $tools));
    }

    public function testAnOwnConnectionCarriesItsChoiceAndKeepsBlockedToolsForTheRefusal(): void
    {
        $tools = $this->describe(
            own: [$this->tool('search', true), $this->tool('update', false), $this->tool('remove', false, true)],
            policy: '{"v":1,"tools":{"update":"approve","remove":"block"}}',
        );

        $byName = [];
        foreach ($tools as $tool) {
            $byName[$tool->name] = $tool;
        }
        self::assertSame('auto', $byName['mcp:9:search']->meta[McpToolMeta::CONNECTION_OUTCOME]);
        self::assertSame('approve', $byName['mcp:9:update']->meta[McpToolMeta::CONNECTION_OUTCOME]);
        self::assertSame('block', $byName['mcp:9:remove']->meta[McpToolMeta::CONNECTION_OUTCOME]);
        self::assertSame('https://crm.example.com/mcp', $byName['mcp:9:update']->meta[McpToolMeta::SERVER_URL]);
    }

    public function testDestructiveToolsDefaultToApproveAndWritesToAuto(): void
    {
        $tools = $this->describe(own: [$this->tool('update', false, false), $this->tool('remove', false, true)]);

        self::assertSame('auto', $tools[0]->meta[McpToolMeta::CONNECTION_OUTCOME]);
        self::assertSame('approve', $tools[1]->meta[McpToolMeta::CONNECTION_OUTCOME]);
    }

    public function testAToolWithoutHintsIsDestructiveAndAsks(): void
    {
        $bare = ['name' => 'mystery', 'description' => 'mystery', 'inputSchema' => ['type' => 'object'], 'annotations' => []];
        $tools = $this->describe(own: [$bare, $this->tool('change', false)]);

        foreach ($tools as $tool) {
            self::assertSame(SideEffect::Destructive, $tool->sideEffect);
            self::assertSame('approve', $tool->meta[McpToolMeta::CONNECTION_OUTCOME]);
        }
    }

    /** A shared connection hands out reads; a tool nobody declared a read is not one. */
    public function testASharedConnectionPublishesOnlyToolsDeclaredReadOnly(): void
    {
        $bare = ['name' => 'mystery', 'description' => 'mystery', 'inputSchema' => ['type' => 'object'], 'annotations' => []];
        $tools = $this->describe(shared: [$this->tool('search', true), $bare, $this->tool('odd', true, true)]);

        self::assertSame(['mcp:9:search'], array_map(static fn ($tool): string => $tool->name, $tools));
    }

    /**
     * @param list<array{name: string, description: string, inputSchema: array<string, mixed>, annotations: array<string, mixed>}> $own
     * @param list<array{name: string, description: string, inputSchema: array<string, mixed>, annotations: array<string, mixed>}> $shared
     *
     * @return list<\App\Service\Tool\ToolDescriptor>
     */
    private function describe(array $own = [], array $shared = [], string $policy = '{}'): array
    {
        $server = (new McpServerConfig())->setName('Company')->setUrl('https://crm.example.com/mcp')->setToolPolicy($policy);
        (new \ReflectionProperty($server, 'id'))->setValue($server, 9);
        $registry = $this->createStub(McpToolRegistry::class);
        $registry->method('catalogForUser')->willReturn([] === $own ? [] : [['server' => $server, 'tools' => $own]]);
        $registry->method('sharedCatalogFor')->willReturn([] === $shared ? [] : [['server' => $server, 'tools' => $shared]]);
        $adapter = $this->createStub(McpToolCatalogAdapter::class);
        $adapter->method('namespace')->willReturnCallback(static fn (int $id, string $tool): string => 'mcp__'.$id.'__'.$tool);

        return (new McpClientToolSource($registry, $adapter, new McpToolPermissions()))->describe(7);
    }

    /**
     * @return array{name: string, description: string, inputSchema: array<string, mixed>, annotations: array<string, mixed>}
     */
    private function tool(string $name, bool $readOnly, ?bool $destructive = null): array
    {
        $annotations = ['readOnlyHint' => $readOnly];
        if (null !== $destructive) {
            $annotations['destructiveHint'] = $destructive;
        }

        return [
            'name' => $name,
            'description' => $name,
            'inputSchema' => ['type' => 'object'],
            'annotations' => $annotations,
        ];
    }
}
