<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Classification;

use App\Entity\File;
use App\Entity\Message;
use App\Service\Message\Classification\SorterInput;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

final class SorterInputTest extends TestCase
{
    public function testTheSorterReadsTheMessageFieldsWithoutAStepVote(): void
    {
        $data = (new SorterInput())->for($this->message([]));

        self::assertSame(
            ['BDATETIME' => '20260607120000', 'BFILEPATH' => '', 'BTOPIC' => '', 'BLANG' => 'en', 'BTEXT' => 'Make a chart', 'BFILETEXT' => '', 'BFILE' => 0, 'BWEBSEARCH' => 0],
            $data,
        );
    }

    public function testAttachedFilesAreNamedByTheirKind(): void
    {
        $data = (new SorterInput())->for($this->message(['csv' => '', '' => 'image/png'], fileType: 'csv', fileText: 'region;sales'));

        self::assertSame(['csv', 'csv, image/png', 2, 'region;sales'], [$data['BFILETYPE'] ?? null, $data['BATTACHED_FILES'] ?? null, $data['BATTACHED_COUNT'] ?? null, $data['BFILETEXT']]);
    }

    /**
     * @param array<string, string> $files type => mime
     */
    private function message(array $files, string $fileType = '', string $fileText = ''): Message
    {
        $message = $this->createStub(Message::class);
        $message->method('getDateTime')->willReturn('20260607120000');
        $message->method('getFilePath')->willReturn('');
        $message->method('getTopic')->willReturn('');
        $message->method('getLanguage')->willReturn('');
        $message->method('getText')->willReturn('Make a chart');
        $message->method('getFileText')->willReturn($fileText);
        $message->method('getFile')->willReturn(0);
        $message->method('getFileType')->willReturn($fileType);
        $attached = [];
        foreach ($files as $type => $mime) {
            $file = $this->createStub(File::class);
            $file->method('getFileType')->willReturn((string) $type);
            $file->method('getFileMime')->willReturn($mime);
            $attached[] = $file;
        }
        $message->method('getFiles')->willReturn(new ArrayCollection($attached));

        return $message;
    }
}
