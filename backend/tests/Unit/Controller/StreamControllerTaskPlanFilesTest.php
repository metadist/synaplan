<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\AI\Service\AiFacade;
use App\Controller\StreamController;
use App\Entity\File;
use App\Service\File\DocumentGeneratorService;
use App\Service\File\UserUploadPathBuilder;
use App\Service\GuestSessionService;
use App\Service\Media\MediaCancellationStore;
use App\Service\MemoryExtractionDispatcher;
use App\Service\Message\MessageForwardingService;
use App\Service\Message\MessageProcessor;
use App\Service\ModelConfigService;
use App\Service\PromptService;
use App\Service\RateLimitService;
use App\Service\WidgetService;
use App\Service\WidgetSessionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit coverage for {@see StreamController::persistTaskPlanFiles()}.
 *
 * Issue #1055: in multi-node task plans the FIRST output file was only
 * persisted via the legacy BFILE/BFILEPATH columns — never as a File
 * entity. History serializes only the Message<->File relation, so a
 * generated document vanished from the chat after a page reload. The
 * helper now registers index 0 too, except for image/video/audio, which
 * the legacy single-file channel already renders inline on reload
 * (registering those twice would duplicate the media player).
 */
class StreamControllerTaskPlanFilesTest extends TestCase
{
    private StreamController $controller;

    protected function setUp(): void
    {
        $this->controller = new StreamController(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(AiFacade::class),
            $this->createMock(MessageProcessor::class),
            new NullLogger(),
            $this->createMock(ModelConfigService::class),
            $this->createMock(WidgetService::class),
            $this->createMock(WidgetSessionService::class),
            $this->createMock(GuestSessionService::class),
            $this->createMock(RateLimitService::class),
            '/tmp/upload',
            $this->createMock(UserUploadPathBuilder::class),
            $this->createMock(PromptService::class),
            $this->createMock(MessageForwardingService::class),
            $this->createMock(MemoryExtractionDispatcher::class),
            $this->createMock(DocumentGeneratorService::class),
            $this->createMock(MediaCancellationStore::class),
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
        // frontend already renders inline on reload — registering it again
        // would double the player. Extra files are always registered.
        $entities = $this->invokeHelper([
            [
                'path' => '/api/v1/files/uploads/7/2026/06/poem.mp3',
                'type' => 'audio',
                'local_path' => '7/2026/06/poem.mp3',
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
        $reflection = new \ReflectionMethod(StreamController::class, 'persistTaskPlanFiles');

        /** @var list<File> $result */
        $result = $reflection->invoke($this->controller, $taskFiles, 7);

        return $result;
    }
}
