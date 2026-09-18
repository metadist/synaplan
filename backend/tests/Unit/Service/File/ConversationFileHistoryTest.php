<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\Service\File\ConversationFile;
use App\Service\File\ConversationFileCatalog;
use App\Service\File\ConversationFileHistory;
use PHPUnit\Framework\TestCase;

class ConversationFileHistoryTest extends TestCase
{
    public function testForChatSerializesCatalogEntries(): void
    {
        $entry = new ConversationFile(
            'file:77',
            'contract.pdf',
            ConversationFile::CATEGORY_DOCUMENT,
            ConversationFile::ORIGIN_UPLOADED,
            '/tmp/contract.pdf',
            '13/000/contract.pdf',
            77,
            100,
            'IN',
            'Clause 1.',
        );

        $catalog = $this->createMock(ConversationFileCatalog::class);
        $catalog->expects($this->once())
            ->method('buildForChat')
            ->with(7, 42, false)
            ->willReturn([$entry]);

        $history = new ConversationFileHistory($catalog);

        $this->assertSame([
            [
                'id' => 77,
                'reference' => 'file:77',
                'name' => 'contract.pdf',
                'category' => 'document',
                'origin' => 'uploaded',
                'fileType' => 'pdf',
                'messageId' => 100,
                'hasText' => true,
            ],
        ], $history->forChat(7, 42));
    }
}
