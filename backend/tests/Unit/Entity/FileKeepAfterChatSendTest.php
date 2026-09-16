<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\File;
use PHPUnit\Framework\TestCase;

final class FileKeepAfterChatSendTest extends TestCase
{
    public function testStagedChatAttachmentBecomesPermanentWhenSent(): void
    {
        $file = new File();
        $file->setSource('chat_attachment');
        $file->setEphemeral(true);

        $file->keepAfterChatSend(false);

        self::assertFalse($file->isEphemeral());
        self::assertSame('chat_attachment', $file->getSource());
    }

    public function testIncognitoKeepsTheAttachmentEphemeral(): void
    {
        $file = new File();
        $file->setSource('chat_attachment');
        $file->setEphemeral(true);

        $file->keepAfterChatSend(true);

        self::assertTrue($file->isEphemeral());
    }

    public function testLibraryFilesAreUnchanged(): void
    {
        $file = new File();
        $file->setSource('web_upload');
        $file->setEphemeral(false);

        $file->keepAfterChatSend(false);

        self::assertFalse($file->isEphemeral());
        self::assertSame('web_upload', $file->getSource());
    }
}
