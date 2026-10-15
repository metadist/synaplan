<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat\Stream;

use App\Entity\File;
use App\Repository\FileRepository;
use App\Service\Chat\Stream\StreamGeneratedFiles;
use App\Service\File\GeneratedFileWriter;
use App\Service\Media\GeneratedFileRegistrar;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit coverage for {@see StreamGeneratedFiles::persistTaskPlanFiles()}.
 *
 * Issue #1055: in multi-node task plans the FIRST output file was only
 * persisted via the legacy BFILE/BFILEPATH columns — never as a File
 * entity. History serializes only the Message<->File relation, so a
 * generated document vanished from the chat after a page reload. The
 * helper now registers index 0 too, except for image/video/audio, which
 * the legacy single-file channel already renders inline on reload
 * (attaching those to the message would duplicate the media player).
 * Since #1251, index-0 media is still written to BFILES (gallery + optional
 * TTS source_text) via GeneratedFileRegistrar, but is not returned for
 * Message::addFile().
 */
class StreamTaskPlanFilesTest extends TestCase
{
    private StreamGeneratedFiles $files;

    protected function setUp(): void
    {
        $this->files = new StreamGeneratedFiles(
            $this->createStub(GeneratedFileWriter::class),
            new GeneratedFileRegistrar($this->createStub(FileRepository::class), new NullLogger(), '/tmp/upload'),
            new NullLogger(),
        );
    }

    public function testRegistersPrimaryDocumentFile(): void
    {
        // The #1055 repro: a document as the FIRST (often only) output file.
        $entities = $this->invokeHelper([
            [
                'path' => '/api/v1/files/uploads/7/2026/06/summary.docx',
                'type' => 'document',
                'local_path' => '7/2026/06/summary.docx',
            ],
        ]);

        self::assertCount(1, $entities);
        self::assertSame('7/2026/06/summary.docx', $entities[0]->getFilePath());
        self::assertSame('document', $entities[0]->getFileType());
        self::assertSame('summary.docx', $entities[0]->getFileName());
    }

    public function testSkipsPrimaryMediaFileButRegistersExtras(): void
    {
        // Index 0 media rides the legacy BFILE/BFILEPATH channel, which the
        // frontend already renders inline on reload — attaching it to the
        // message again would double the player. Extra files are always
        // returned for Message::addFile(). Index 0 is still registered into
        // BFILES (Generated gallery / #1251 source text) but omitted here.
        $entities = $this->invokeHelper([
            [
                'path' => '/api/v1/files/uploads/7/2026/06/poem.mp3',
                'type' => 'audio',
                'local_path' => '7/2026/06/poem.mp3',
                'source_text' => 'a short poem',
            ],
            [
                'path' => '/api/v1/files/uploads/7/2026/06/spring.png',
                'type' => 'image',
                'local_path' => '7/2026/06/spring.png',
            ],
        ]);

        self::assertCount(1, $entities);
        self::assertSame('7/2026/06/spring.png', $entities[0]->getFilePath());
    }

    public function testRegistersPrimaryDocumentAndExtraMedia(): void
    {
        $entities = $this->invokeHelper([
            [
                'path' => '/api/v1/files/uploads/7/2026/06/meeting.ics',
                'type' => 'document',
                'local_path' => '7/2026/06/meeting.ics',
            ],
            [
                'path' => '/api/v1/files/uploads/7/2026/06/readout.mp3',
                'type' => 'audio',
                'local_path' => '7/2026/06/readout.mp3',
            ],
        ]);

        self::assertSame(
            ['7/2026/06/meeting.ics', '7/2026/06/readout.mp3'],
            array_map(static fn (File $f) => $f->getFilePath(), $entities),
        );
    }

    public function testSkipsMalformedDescriptors(): void
    {
        $entities = $this->invokeHelper([
            'not-an-array',
            ['type' => 'document'], // no path
            [
                'path' => '/api/v1/files/uploads/7/2026/06/orphan.docx',
                'type' => 'document',
                'local_path' => null, // never written to the upload dir
            ],
        ]);

        self::assertSame([], $entities);
    }

    /**
     * @param array<int|string, mixed> $taskFiles
     *
     * @return list<File>
     */
    private function invokeHelper(array $taskFiles): array
    {
        return $this->files->persistTaskPlanFiles($taskFiles, 7);
    }
}
