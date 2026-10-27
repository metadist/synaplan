<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mcp\Notion;

use App\Entity\McpServerConfig;
use App\Entity\NotionActivity;
use App\Repository\NotionActivityRepository;
use App\Service\Mcp\Notion\NotionActivityLog;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class NotionActivityLogTest extends TestCase
{
    /** @var list<NotionActivity> */
    private array $saved = [];

    public function testRecordsACreatedPageWithItsTitleAndLink(): void
    {
        $answer = (string) json_encode(['pages' => [['id' => '39797ad6-0eb3-806d-b59e-ef7653838664', 'url' => 'https://www.notion.so/39797ad60eb3806db59eef7653838664']]]);

        $this->log()->recordToolCall($this->notion(), 'notion-create-pages', [
            'pages' => [['properties' => ['title' => 'Meeting notes'], 'content' => 'Hello']],
        ], ['content' => [['type' => 'text', 'text' => $answer]], 'isError' => false]);

        self::assertCount(1, $this->saved);
        self::assertSame(NotionActivity::ACTION_CREATED, $this->saved[0]->getAction());
        self::assertSame('Meeting notes', $this->saved[0]->getTitle());
        self::assertSame('https://www.notion.so/39797ad60eb3806db59eef7653838664', $this->saved[0]->getUrl());
    }

    public function testRecordsAnEditUnderThePageLink(): void
    {
        $this->log()->recordToolCall($this->notion(), 'update-page', [
            'page_id' => '39797ad6-0eb3-806d-b59e-ef7653838664',
            'command' => 'update_properties',
            'properties' => ['title' => 'Roadmap 2027'],
        ], ['content' => [['type' => 'text', 'text' => '{}']], 'isError' => false]);

        self::assertSame(NotionActivity::ACTION_UPDATED, $this->saved[0]->getAction());
        self::assertSame('Roadmap 2027', $this->saved[0]->getTitle());
    }

    public function testSkipsReadsErrorsAndFileOnlyInserts(): void
    {
        $ok = ['content' => [['type' => 'text', 'text' => '{}']], 'isError' => false];

        $this->log()->recordToolCall($this->notion(), 'notion-search', ['query' => 'x'], $ok);
        $this->log()->recordToolCall($this->notion(), 'notion-create-pages', ['pages' => []], ['content' => [], 'isError' => true]);
        $this->log()->recordToolCall($this->notion(), 'update-page', [
            'page_id' => '39797ad6-0eb3-806d-b59e-ef7653838664',
            'command' => 'insert_content',
            'content' => '<file source="file-upload://abc"></file>',
        ], $ok);

        self::assertSame([], $this->saved);
    }

    public function testIgnoresOtherServers(): void
    {
        $server = new McpServerConfig();
        $server->setUserId(4)->setName('Other')->setUrl('https://mcp.example.com/mcp');

        $this->log()->recordToolCall($server, 'create-pages', ['pages' => []], ['content' => [], 'isError' => false]);

        self::assertSame([], $this->saved);
    }

    private function log(): NotionActivityLog
    {
        $repository = $this->createStub(NotionActivityRepository::class);
        $repository->method('save')->willReturnCallback(function (NotionActivity $activity): void {
            $this->saved[] = $activity;
        });
        $repository->method('lastTitle')->willReturn(null);

        return new NotionActivityLog($repository, new NullLogger());
    }

    private function notion(): McpServerConfig
    {
        $server = new McpServerConfig();
        $server->setUserId(4)->setName('Notion')->setUrl('https://mcp.notion.com/mcp');

        return $server;
    }
}
