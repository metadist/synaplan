<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Email\Turn;

use App\Service\Email\Turn\EmailReplyAttachments;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class EmailReplyAttachmentsTest extends TestCase
{
    private string $uploadDir = '';

    protected function setUp(): void
    {
        $this->uploadDir = (string) realpath(sys_get_temp_dir()).'/email-attachments-'.bin2hex(random_bytes(4));
        mkdir($this->uploadDir.'/7', 0o775, true);
        file_put_contents($this->uploadDir.'/7/cat.png', 'png');
        file_put_contents($this->uploadDir.'/7/invite.ics', 'ics');
        file_put_contents(dirname($this->uploadDir).'/outside.png', 'png');
    }

    protected function tearDown(): void
    {
        array_map('unlink', [$this->uploadDir.'/7/cat.png', $this->uploadDir.'/7/invite.ics', dirname($this->uploadDir).'/outside.png']);
        rmdir($this->uploadDir.'/7');
        rmdir($this->uploadDir);
    }

    public function testTheAnswersOwnFileIsFoundByPathOrServeUrl(): void
    {
        $files = new EmailReplyAttachments($this->uploadDir, new NullLogger());

        self::assertSame($this->uploadDir.'/7/cat.png', $files->primary(['media_type' => 'image', 'local_path' => '7/cat.png']));
        self::assertSame($this->uploadDir.'/7/cat.png', $files->primary(['media_type' => 'IMAGE', 'file' => ['path' => '/api/v1/files/uploads/7/cat.png']]));
        self::assertSame($this->uploadDir.'/7/invite.ics', $files->primary(['file' => ['type' => 'document', 'path' => '/api/v1/files/uploads/7/invite.ics']]));
    }

    public function testNothingOutsideTheUploadsMissingOrOfAnotherKindIsAttached(): void
    {
        $files = new EmailReplyAttachments($this->uploadDir, new NullLogger());

        self::assertNull($files->primary(['media_type' => 'image', 'local_path' => '../outside.png']));
        self::assertNull($files->primary(['media_type' => 'image', 'local_path' => '7/missing.png']));
        self::assertNull($files->primary(['media_type' => 'text', 'local_path' => '7/cat.png']));
        self::assertNull($files->primary(['media_type' => 'image']));
    }

    public function testAMultiStepAnswerAttachesItsOutputsBeyondTheFirst(): void
    {
        $files = new EmailReplyAttachments($this->uploadDir, new NullLogger());

        self::assertSame([$this->uploadDir.'/7/invite.ics'], $files->additional(['files' => [
            ['type' => 'image', 'local_path' => '7/cat.png'],
            ['type' => 'document', 'local_path' => '7/invite.ics'],
            'not a file',
            ['type' => 'image', 'local_path' => '7/missing.png'],
        ]]));
        self::assertSame([], $files->additional(['files' => [['type' => 'image', 'local_path' => '7/cat.png']]]));
        self::assertSame($this->uploadDir.'/7/voice.mp3', $files->uploaded('7/voice.mp3'));
    }
}
