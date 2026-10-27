<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mcp\Notion;

use App\Service\Mcp\Notion\NotionWriteGuard;
use PHPUnit\Framework\TestCase;

final class NotionWriteGuardTest extends TestCase
{
    private const NOTION = 'https://mcp.notion.com/mcp';

    public function testPageCommandsThatKeepContentMaySend(): void
    {
        self::assertNull(NotionWriteGuard::refusal(self::NOTION, 'notion-update-page', ['command' => 'update_properties']));
        self::assertNull(NotionWriteGuard::refusal(self::NOTION, 'notion-update-page', ['command' => 'update_content']));
        self::assertNull(NotionWriteGuard::refusal(self::NOTION, 'notion-update-page', ['command' => 'insert_content', 'content' => 'Summary']));
        self::assertNull(NotionWriteGuard::refusal(self::NOTION, 'notion-create-pages', []));
    }

    public function testRefusesPageCommandsThatCanRemoveContent(): void
    {
        foreach (['replace_content', 'apply_template', ''] as $command) {
            $refusal = NotionWriteGuard::refusal(self::NOTION, 'notion-update-page', ['command' => $command]);
            self::assertNotNull($refusal, $command);
            self::assertStringContainsString('nothing was changed in Notion', $refusal);
        }
        self::assertNotNull(NotionWriteGuard::refusal(self::NOTION, 'notion-update-page', []));
        self::assertNotNull(NotionWriteGuard::refusal(self::NOTION, 'notion-update-page', ['command' => 'update_content', 'allow_deleting_content' => true]));
        self::assertNotNull(NotionWriteGuard::refusal(self::NOTION, 'notion-create-pages', ['allow_deleting_content' => true]));
    }

    public function testRefusesTrashingADatabaseOrDroppingAColumn(): void
    {
        self::assertNotNull(NotionWriteGuard::refusal(self::NOTION, 'notion-update-data-source', ['data_source_id' => 'x', 'in_trash' => true]));
        self::assertNotNull(NotionWriteGuard::refusal(self::NOTION, 'notion-update-data-source', ['statements' => 'ADD COLUMN "Owner" PEOPLE; drop  column "Old"']));
        self::assertNull(NotionWriteGuard::refusal(self::NOTION, 'notion-update-data-source', ['statements' => 'ADD COLUMN "Owner" PEOPLE', 'in_trash' => false]));
        self::assertNull(NotionWriteGuard::refusal(self::NOTION, 'notion-update-data-source', ['title' => 'Tasks']));
    }

    public function testOtherServersAreLeftAlone(): void
    {
        self::assertNull(NotionWriteGuard::refusal('https://crm.example.com/mcp', 'notion-update-page', ['command' => 'replace_content']));
        self::assertSame(['allow_async' => true], NotionWriteGuard::normalize('https://crm.example.com/mcp', 'notion-create-pages', ['allow_async' => true]));
    }

    public function testBackgroundExecutionIsSwitchedOffSoTheAnswerReportsTheRealResult(): void
    {
        self::assertSame(
            ['pages' => [['properties' => ['title' => 'Meeting notes']]]],
            NotionWriteGuard::normalize(self::NOTION, 'notion-create-pages', ['pages' => [['properties' => ['title' => 'Meeting notes']]], 'allow_async' => true]),
        );
    }
}
