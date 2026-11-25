<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Widget;

use App\Entity\File;
use App\Entity\Widget;
use App\Entity\WidgetSession;
use App\Repository\VisitorUploadRepository;
use App\Repository\WidgetRepository;
use App\Repository\WidgetSessionRepository;
use App\Service\RAG\VectorStorage\VectorStorageFacade;
use App\Service\Widget\VisitorUploadVectorPurge;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class VisitorUploadVectorPurgeTest extends TestCase
{
    private const OWNER_ID = 7;

    public function testChunksAreDeletedUnderTheWidgetOwnerAndTheFileIsNoLongerMarkedIndexed(): void
    {
        $file = $this->upload(359, 40)->markVectorized()->setChunkCount(3);
        $storage = $this->createMock(VectorStorageFacade::class);
        $storage->expects($this->once())->method('deleteByFile')->with(self::OWNER_ID, 359)->willReturn(3);

        $summary = $this->purge([$file], $storage)->run();

        self::assertSame(['files' => 1, 'chunks' => 3, 'ownerless' => 0], $summary);
        self::assertSame([File::VECTOR_STATE_NONE, 0, 'extracted'], [$file->getVectorState(), $file->getChunkCount(), $file->getStatus()]);
    }

    public function testADryRunCountsWithoutDeleting(): void
    {
        $file = $this->upload(359, 40)->markVectorized();
        $storage = $this->createMock(VectorStorageFacade::class);
        $storage->expects($this->never())->method('deleteByFile');
        $storage->expects($this->once())->method('getFileChunkInfo')->with(self::OWNER_ID, 359)->willReturn(['chunks' => 2, 'groupKey' => 'WIDGET:wdg_test']);

        $summary = $this->purge([$file], $storage)->run(true);

        self::assertSame(['files' => 1, 'chunks' => 2, 'ownerless' => 0], $summary);
        self::assertSame(File::VECTOR_STATE_VECTORIZED, $file->getVectorState());
    }

    public function testAnUploadWhoseSessionIsGoneIsCountedAndLeftAlone(): void
    {
        $storage = $this->createMock(VectorStorageFacade::class);
        $storage->expects($this->never())->method('deleteByFile');

        $summary = $this->purge([$this->upload(360, 99)], $storage)->run();

        self::assertSame(['files' => 1, 'chunks' => 0, 'ownerless' => 1], $summary);
    }

    /**
     * @param list<File> $files
     */
    private function purge(array $files, VectorStorageFacade $storage): VisitorUploadVectorPurge
    {
        $uploads = $this->createStub(VisitorUploadRepository::class);
        $uploads->method('pageAfter')->willReturnCallback(
            static fn (int $afterId): array => 0 === $afterId ? $files : [],
        );

        $session = (new WidgetSession())->setWidgetId('wdg_test');
        $sessions = $this->createStub(WidgetSessionRepository::class);
        $sessions->method('find')->willReturnCallback(static fn (mixed $id): ?WidgetSession => 40 === $id ? $session : null);

        $widget = (new Widget())->setOwnerId(self::OWNER_ID);
        $widgets = $this->createStub(WidgetRepository::class);
        $widgets->method('findOneByWidgetId')->willReturn($widget);

        return new VisitorUploadVectorPurge($uploads, $sessions, $widgets, $storage, $this->createStub(EntityManagerInterface::class));
    }

    private function upload(int $id, int $sessionId): File
    {
        $file = (new File())->setUserId(0)->setUserSessionId($sessionId);
        (new \ReflectionProperty(File::class, 'id'))->setValue($file, $id);

        return $file;
    }
}
