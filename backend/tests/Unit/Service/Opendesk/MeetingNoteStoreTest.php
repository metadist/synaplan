<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Opendesk;

use App\Service\Opendesk\MeetingNoteStore;
use PHPUnit\Framework\TestCase;

final class MeetingNoteStoreTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/synaplan-notes-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public function testSaveListsAndReadsWithoutKeepingAudio(): void
    {
        $store = new MeetingNoteStore($this->directory);
        $saved = $store->save(7, [
            'source' => 'jitsi',
            'text' => 'Ada: We ship on Friday.',
            'room' => '!project:example',
            'folder' => '/Meetings',
            'language' => 'en',
            'started_by' => 'ada',
            'meeting_id' => 'standup',
        ]);

        self::assertFalse($saved['audio_retained']);
        self::assertStringStartsWith('note_', $saved['id']);
        self::assertStringContainsString('Audio was not kept.', $saved['markdown']);

        $list = $store->list(7);
        self::assertCount(1, $list);
        self::assertSame('standup', $list[0]['meeting_id']);
        self::assertArrayNotHasKey('text', $list[0]);

        $loaded = $store->get(7, $saved['id']);
        self::assertSame('Ada: We ship on Friday.', $loaded['text']);
        self::assertNull($store->get(8, $saved['id']));
        self::assertNull($store->get(7, '../note_aaaaaaaaaaaaaaaa'));
    }

    public function testRejectsAnUnknownSource(): void
    {
        $store = new MeetingNoteStore($this->directory);

        $this->expectException(\InvalidArgumentException::class);
        $store->save(1, ['source' => 'talk', 'text' => 'hello']);
    }
}
