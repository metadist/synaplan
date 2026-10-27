<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mcp\Notion;

use App\Service\Mcp\Notion\NotionImagePlan;
use App\Service\Multitask\Plan\TaskPlan;
use PHPUnit\Framework\TestCase;

final class NotionImagePlanTest extends TestCase
{
    private const ASK = 'Such mal in meinem ganzen Notion nach Bildern und wenn du das erste findest, hänge es hier im Chat an';

    public function testInstallsSearchImageAndReplyOnAChatOnlyPlan(): void
    {
        $payload = NotionImagePlan::install(TaskPlan::singleChatPlan()->toArray(), self::ASK, 3, 'notion-search', 'notion-get-file-download-urls', 'Notion');

        self::assertSame('n_notion_reply', $payload['reply_node']);
        self::assertSame(['n_notion_search', 'n_notion_image', 'n_notion_reply'], array_column($payload['tasks'], 'id'));
        self::assertSame(['query' => ''], $payload['tasks'][0]['inputs']['arguments']);
        self::assertSame('notion-search', $payload['tasks'][0]['params']['tool']);
        self::assertSame(['pages' => '$n_notion_search.text'], $payload['tasks'][1]['inputs']['arguments']);
        self::assertSame(['$n_notion_image.file'], $payload['tasks'][2]['inputs']['attachments']);
    }

    public function testLeavesAPlanThatDoesNotNameNotion(): void
    {
        $payload = TaskPlan::singleChatPlan()->toArray();

        self::assertSame(
            $payload,
            NotionImagePlan::install($payload, 'Zeig mir ein Bild und häng es hier an', 3, 'notion-search', 'notion-get-file-download-urls', 'Notion'),
        );
    }

    public function testLeavesAPlanThatAlreadyFetchesTheFile(): void
    {
        $payload = TaskPlan::singleChatPlan()->toArray();
        $payload['tasks'][] = [
            'id' => 'n2',
            'capability' => 'mcp_fetch',
            'depends_on' => [],
            'inputs' => ['arguments' => ['references' => ['notion-file-block://a']]],
            'params' => ['server_id' => 3, 'tool' => 'notion-get-file-download-urls'],
        ];

        self::assertSame(
            $payload,
            NotionImagePlan::install($payload, self::ASK, 3, 'notion-search', 'notion-get-file-download-urls', 'Notion'),
        );
    }

    public function testReusesAnExistingSearchAndDropsTheTextAttachmentTool(): void
    {
        $payload = [
            'version' => 1,
            'language' => 'en',
            'reply_node' => 'n3',
            'tasks' => [
                ['id' => 'n1', 'capability' => 'mcp_fetch', 'depends_on' => [], 'inputs' => ['arguments' => ['query' => '']], 'params' => ['server_id' => 3, 'tool' => 'notion-search']],
                ['id' => 'n2', 'capability' => 'mcp_fetch', 'depends_on' => ['n1'], 'inputs' => ['arguments' => ['file_upload_id' => '$n1.file_upload_id']], 'params' => ['server_id' => 3, 'tool' => 'notion-download-attachment']],
                ['id' => 'n3', 'capability' => 'compose_reply', 'depends_on' => ['n2'], 'inputs' => ['attachments' => ['$n2.file']], 'params' => []],
            ],
        ];

        $next = NotionImagePlan::install($payload, self::ASK, 3, 'notion-search', 'notion-get-file-download-urls', 'Notion');

        self::assertSame(['n_notion_image', 'n_notion_reply', 'n1'], array_column($next['tasks'], 'id'));
        self::assertSame(['n1'], $next['tasks'][0]['depends_on']);
    }

    public function testQueryKeepsANamedPageAndDropsTheRequestWords(): void
    {
        self::assertSame('', NotionImagePlan::query(self::ASK));
        self::assertSame('Launch plan', NotionImagePlan::query('Häng das Bild von der Seite "Launch plan" aus Notion an'));
        self::assertSame('logo', NotionImagePlan::query('Such in Notion nach dem Logo und häng es an'));
    }

    public function testRecognisesTheRequestInEveryLocale(): void
    {
        self::assertTrue(NotionImagePlan::wantsAttachedImage('Show me the first picture from my Notion page'));
        self::assertTrue(NotionImagePlan::wantsAttachedImage('Busca en Notion la primera imagen y adjúntala aquí'));
        self::assertTrue(NotionImagePlan::wantsAttachedImage('Cherche la première image dans Notion et joins-la ici'));
        self::assertTrue(NotionImagePlan::wantsAttachedImage('Notion sayfasındaki ilk resmi bul ve buraya ekle'));
        self::assertFalse(NotionImagePlan::wantsAttachedImage('Show me the meeting notes from Notion'));
    }
}
