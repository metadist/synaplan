<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mcp\Notion;

use App\Entity\McpServerConfig;
use App\Service\Mcp\Notion\NotionFetchArguments;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class NotionFetchArgumentsTest extends TestCase
{
    private NotionFetchArguments $arguments;

    protected function setUp(): void
    {
        $this->arguments = new NotionFetchArguments(new NullLogger());
    }

    public function testAPageIdAndAUrlAreLeftAlone(): void
    {
        $server = $this->notion();
        $tool = ['name' => 'notion-fetch'];

        self::assertSame(
            ['id' => '39797ad6-0eb3-806d-b59e-ef7653838664'],
            $this->arguments->complete($server, $tool, ['id' => '39797ad6-0eb3-806d-b59e-ef7653838664'], 'Metadist GmbH'),
        );
        self::assertSame(
            ['id' => 'https://www.notion.so/Metadist-39797ad60eb3806db59eef7653838664'],
            $this->arguments->complete($server, $tool, ['id' => 'https://www.notion.so/Metadist-39797ad60eb3806db59eef7653838664'], ''),
        );
    }

    public function testASearchPayloadBecomesThePageThePersonNamed(): void
    {
        $payload = (string) json_encode([
            'results' => [
                ['id' => '11111111-1111-1111-1111-111111111111', 'title' => 'Knowledge Base', 'type' => 'page'],
                ['id' => '39797ad6-0eb3-806d-b59e-ef7653838664', 'title' => 'Metadist GmbH', 'type' => 'page'],
            ],
        ]);

        $completed = $this->arguments->complete(
            $this->notion(),
            ['name' => 'fetch'],
            ['id' => $payload],
            'Fasse die Seite Metadist GmbH zusammen',
        );

        self::assertSame('39797ad6-0eb3-806d-b59e-ef7653838664', $completed['id']);
    }

    public function testWithoutATitleMatchTheFirstResultIsUsed(): void
    {
        $payload = [
            'results' => [
                ['id' => '39797ad6-0eb3-806d-b59e-ef7653838664', 'title' => 'Metadist GmbH', 'type' => 'page'],
                ['title' => 'Only a url', 'url' => 'https://app.notion.com/p/abc'],
            ],
        ];

        $completed = $this->arguments->complete($this->notion(), ['name' => 'notion-fetch'], ['id' => $payload], 'Was steht in Notion?');

        self::assertSame('39797ad6-0eb3-806d-b59e-ef7653838664', $completed['id']);
    }

    public function testOtherServersAndToolsAreUntouched(): void
    {
        $blob = '{"results":[{"id":"39797ad6-0eb3-806d-b59e-ef7653838664","title":"Metadist GmbH"}]}';
        $crm = (new McpServerConfig())->setUrl('https://crm.example.com/mcp');

        self::assertSame(
            ['id' => $blob],
            $this->arguments->complete($crm, ['name' => 'notion-fetch'], ['id' => $blob], 'Metadist GmbH'),
        );
        self::assertSame(
            ['query' => 'Metadist GmbH'],
            $this->arguments->complete($this->notion(), ['name' => 'notion-search'], ['query' => 'Metadist GmbH'], 'Metadist GmbH'),
        );
    }

    public function testThePlannerHintIsOnlyForNotionFetch(): void
    {
        self::assertSame(
            ' — id is one page id or URL from a search result, never the whole search output',
            $this->arguments->argumentHint('https://mcp.notion.com/mcp', 'notion-fetch'),
        );
        self::assertSame('', $this->arguments->argumentHint('https://mcp.notion.com/mcp', 'notion-search'));
        self::assertSame('', $this->arguments->argumentHint('https://crm.example.com/mcp', 'fetch'));
    }

    private function notion(): McpServerConfig
    {
        return (new McpServerConfig())->setUrl('https://mcp.notion.com/mcp');
    }
}
