<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mcp\Notion;

use App\Service\Mcp\Notion\NotionReadPlan;
use App\Service\Multitask\Plan\TaskPlan;
use PHPUnit\Framework\TestCase;

final class NotionReadPlanTest extends TestCase
{
    public function testMeetingQuestionReadsMeetingNotesThenAnswers(): void
    {
        $chatId = TaskPlan::singleChatPlan()->toArray()['reply_node'];
        $payload = NotionReadPlan::installMeetings(TaskPlan::singleChatPlan()->toArray(), 'What did we decide in my meetings this week in Notion?', 3, 'notion-query-meeting-notes');

        self::assertSame(['n_notion_meetings', $chatId], array_column($payload['tasks'], 'id'));
        self::assertSame('notion-query-meeting-notes', $payload['tasks'][0]['params']['tool']);
        self::assertSame('the_past_week', $payload['tasks'][0]['inputs']['arguments']['filter']['filters'][0]['filter']['value']['value']);
        self::assertSame(['n_notion_meetings'], $payload['tasks'][1]['depends_on']);
        self::assertStringContainsString('$n_notion_meetings.text', $payload['tasks'][1]['inputs']['text']);
    }

    public function testMeetingFilterFollowsTheNamedTime(): void
    {
        self::assertSame('today', NotionReadPlan::meetingFilter('Was stand heute in der Besprechung?')['filters'][0]['filter']['value']['value']);
        self::assertSame('yesterday', NotionReadPlan::meetingFilter('Notes from yesterday\'s meeting')['filters'][0]['filter']['value']['value']);
        self::assertSame('the_past_month', NotionReadPlan::meetingFilter('Réunions du mois')['filters'][0]['filter']['value']['value']);
    }

    public function testLeavesPlansWithoutMeetingWordsOrWithoutTheTool(): void
    {
        $payload = TaskPlan::singleChatPlan()->toArray();

        self::assertSame($payload, NotionReadPlan::installMeetings($payload, 'Summarize my Roadmap page in Notion', 3, 'notion-query-meeting-notes'));
        self::assertSame($payload, NotionReadPlan::installMeetings($payload, 'My meetings in Notion', 3, ''));
        self::assertSame($payload, NotionReadPlan::installMeetings($payload, 'My meetings in Notion', 3, 'notion-query-meeting-notes', ['compose_reply']));
    }

    public function testDatabaseQuestionSearchesFetchesAndReadsRows(): void
    {
        $payload = NotionReadPlan::installDatabase(
            TaskPlan::singleChatPlan()->toArray(),
            'Show the open rows of my Tasks database in Notion',
            3,
            'notion-search',
            'notion-fetch',
            'notion-query-data-sources',
        );

        self::assertSame(['n_notion_db_search', 'n_notion_db_fetch', 'n_notion_db_rows'], array_slice(array_column($payload['tasks'], 'id'), 0, 3));
        self::assertSame(['query' => 'tasks'], $payload['tasks'][0]['inputs']['arguments']);
        self::assertSame(['id' => '$n_notion_db_search.text'], $payload['tasks'][1]['inputs']['arguments']);
        self::assertSame(['mode' => 'rows', 'data_source_url' => '$n_notion_db_fetch.text', 'limit' => 50], $payload['tasks'][2]['inputs']['arguments']['data']);
        self::assertSame(['n_notion_db_rows'], $payload['tasks'][3]['depends_on']);
    }

    public function testQuotedDatabaseNameWins(): void
    {
        self::assertSame('Sales Pipeline', NotionReadPlan::databaseQuery('Wie viele Einträge hat die Datenbank „Sales Pipeline“ in Notion?'));
    }

    public function testDropsOtherNotionReadsTheModelPlanned(): void
    {
        $payload = [
            'version' => 1,
            'language' => 'en',
            'reply_node' => 'n2',
            'tasks' => [
                ['id' => 'n1', 'capability' => 'mcp_fetch', 'depends_on' => [], 'inputs' => ['arguments' => ['query' => 'x']], 'params' => ['server_id' => 3, 'tool' => 'notion-search']],
                ['id' => 'n2', 'capability' => 'chat', 'depends_on' => ['n1'], 'inputs' => ['text' => '$n1.text'], 'params' => []],
            ],
        ];

        $adjusted = NotionReadPlan::installMeetings($payload, 'Meeting notes from today in Notion', 3, 'notion-query-meeting-notes');

        self::assertSame(['n_notion_meetings', 'n2'], array_column($adjusted['tasks'], 'id'));
        self::assertSame(['n_notion_meetings'], $adjusted['tasks'][1]['depends_on']);
    }

    public function testFindsTheDataSourceInAFetchedDatabase(): void
    {
        $text = '<database url="https://www.notion.so/abc"><data-source url="collection://0f5d4c2e-1111-4a2b-9c3d-123456789abc">';

        self::assertSame('collection://0f5d4c2e-1111-4a2b-9c3d-123456789abc', NotionReadPlan::dataSourceUrl($text));
        self::assertNull(NotionReadPlan::dataSourceUrl('no source here'));
    }
}
