<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mcp\Notion;

use App\Entity\McpServerConfig;
use App\Service\Mcp\Notion\NotionToolHints;
use PHPUnit\Framework\TestCase;

final class NotionToolHintsTest extends TestCase
{
    private NotionToolHints $hints;

    protected function setUp(): void
    {
        $this->hints = new NotionToolHints();
    }

    public function testFillsMissingHintsOnlyForTheNotionHost(): void
    {
        $tools = [
            $this->tool('notion-search'),
            $this->tool('create-pages'),
            $this->tool('move-pages'),
            $this->tool('notion-fetch', ['readOnlyHint' => false]),
        ];

        $notion = $this->hints->apply($this->server('https://mcp.notion.com/mcp'), $tools);
        self::assertTrue($notion[0]['annotations']['readOnlyHint']);
        self::assertFalse($notion[0]['annotations']['destructiveHint']);
        self::assertFalse($notion[1]['annotations']['readOnlyHint']);
        self::assertFalse($notion[1]['annotations']['destructiveHint']);
        self::assertTrue($notion[2]['annotations']['destructiveHint']);
        self::assertFalse($notion[3]['annotations']['readOnlyHint']);
        self::assertArrayNotHasKey('destructiveHint', $notion[3]['annotations']);

        $other = $this->hints->apply($this->server('https://crm.example.com/mcp'), $tools);
        self::assertSame([], $other[0]['annotations']);
        self::assertSame([], $other[1]['annotations']);
    }

    public function testUpdatePageIsAWriteEvenWhenNotionCallsItDestructive(): void
    {
        $tools = [$this->tool('notion-update-page', ['readOnlyHint' => false, 'destructiveHint' => true])];

        $annotated = $this->hints->apply($this->server('https://mcp.notion.com/mcp'), $tools);

        self::assertFalse($annotated[0]['annotations']['readOnlyHint']);
        self::assertFalse($annotated[0]['annotations']['destructiveHint']);

        $other = $this->hints->apply($this->server('https://crm.example.com/mcp'), $tools);
        self::assertTrue($other[0]['annotations']['destructiveHint']);
    }

    public function testLeavesUnknownNotionToolsUntouched(): void
    {
        $tools = [$this->tool('something-new')];

        $annotated = $this->hints->apply($this->server('https://mcp.notion.com/mcp'), $tools);

        self::assertSame([], $annotated[0]['annotations']);
    }

    /**
     * @param array<string, mixed> $annotations
     *
     * @return array{name: string, description: string, inputSchema: array<string, mixed>, annotations: array<string, mixed>}
     */
    private function tool(string $name, array $annotations = []): array
    {
        return [
            'name' => $name,
            'description' => '',
            'inputSchema' => [],
            'annotations' => $annotations,
        ];
    }

    private function server(string $url): McpServerConfig
    {
        $server = new McpServerConfig();
        $server->setUserId(1)->setName('Notion')->setUrl($url);

        return $server;
    }
}
