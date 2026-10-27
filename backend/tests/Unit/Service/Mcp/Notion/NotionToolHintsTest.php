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

    public function testHidesUnknownNotionToolsThatDoNotDeclareThemselvesReadOnly(): void
    {
        $tools = [
            $this->tool('notion-something-new'),
            $this->tool('notion-another-new', ['readOnlyHint' => false, 'destructiveHint' => false]),
            $this->tool('notion-new-lookup', ['readOnlyHint' => true]),
        ];

        $annotated = $this->hints->apply($this->server('https://mcp.notion.com/mcp'), $tools);

        self::assertTrue($annotated[0]['annotations']['destructiveHint']);
        self::assertTrue($annotated[1]['annotations']['destructiveHint']);
        self::assertSame(['readOnlyHint' => true], $annotated[2]['annotations']);

        $other = $this->hints->apply($this->server('https://crm.example.com/mcp'), $tools);
        self::assertSame([], $other[0]['annotations']);
    }

    public function testBlocksCalendarAgentAndDeleteToolsEvenWhenNotionCallsThemHarmless(): void
    {
        $names = [
            'notion-delete-databases',
            'notion-delete-comment',
            'notion-calendar-create-events',
            'notion-calendar-list-events',
            'notion-spawn-session',
            'notion-create-attachment',
            'notion-update-folder',
            'notion-memory-search',
        ];
        $tools = array_map(fn (string $name): array => $this->tool($name, ['readOnlyHint' => true]), $names);

        foreach ($this->hints->apply($this->server('https://mcp.notion.com/mcp'), $tools) as $index => $tool) {
            self::assertTrue($tool['annotations']['destructiveHint'], $names[$index]);
            self::assertFalse($tool['annotations']['readOnlyHint'], $names[$index]);
        }
    }

    public function testNewReadAndWriteToolsGetTheirHintsWhenNotionSendsNone(): void
    {
        $read = ['notion-query-meeting-notes', 'notion-query-multiple-data-sources', 'notion-list-recent-pages', 'notion-list-favorite-pages', 'notion-list-page-backlinks', 'notion-get-self', 'notion-get-async-task'];
        $write = ['notion-update-comment', 'notion-set-discussion-resolved', 'notion-create-folder', 'notion-restore-pages'];
        $tools = array_map(fn (string $name): array => $this->tool($name), [...$read, ...$write]);

        $annotated = $this->hints->apply($this->server('https://mcp.notion.com/mcp'), $tools);

        foreach ($annotated as $index => $tool) {
            $isRead = $index < count($read);
            self::assertSame($isRead, $tool['annotations']['readOnlyHint'], $tool['name']);
            self::assertFalse($tool['annotations']['destructiveHint'], $tool['name']);
        }
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
