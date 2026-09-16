<?php

declare(strict_types=1);

namespace App\Tests\Service\File;

use App\Entity\File;
use App\Entity\User;
use App\Repository\FileRepository;
use App\Service\File\FileGroupSorter;
use App\Service\File\FileProcessor;
use App\Service\File\FileStorageService;
use App\Service\File\FileUploadService;
use App\Service\File\VectorizationService;
use App\Service\RAG\VectorStorage\VectorStorageFacade;
use App\Service\RateLimitService;
use App\Service\StorageQuotaService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Store-only uploads (.jar) must reach a terminal file status so the
 * file picker's post-upload poll can stop. They must not hit Tika or
 * consume a FILE_ANALYSIS slot.
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class FileUploadServiceStoreOnlyTest extends TestCase
{
    private FileProcessor&MockObject $fileProcessor;
    private EntityManagerInterface&MockObject $em;
    private RateLimitService&MockObject $rateLimitService;

    protected function setUp(): void
    {
        $this->fileProcessor = $this->createMock(FileProcessor::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->rateLimitService = $this->createMock(RateLimitService::class);
    }

    private function makeService(): FileUploadService
    {
        return new FileUploadService(
            $this->createStub(FileStorageService::class),
            $this->fileProcessor,
            $this->createStub(VectorizationService::class),
            $this->createStub(VectorStorageFacade::class),
            $this->createStub(StorageQuotaService::class),
            $this->rateLimitService,
            $this->createStub(FileGroupSorter::class),
            $this->createStub(FileRepository::class),
            $this->em,
            new NullLogger(),
            '/tmp/uploads',
        );
    }

    private function makeJarFile(): File&MockObject
    {
        $file = $this->createMock(File::class);
        $file->method('getStatus')->willReturn('uploaded');
        $file->method('getFileType')->willReturn('jar');
        $file->method('getFilePath')->willReturn('user/1/mod.jar');
        $file->method('getFileName')->willReturn('mod.jar');
        $file->method('getId')->willReturn(42);

        return $file;
    }

    private function makeUser(): User&MockObject
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);

        return $user;
    }

    public function testProcessFileMarksJarExtractedWithoutTikaOrRateLimit(): void
    {
        $file = $this->makeJarFile();
        $file->expects(self::once())->method('setStatus')->with('extracted');
        $this->em->expects(self::once())->method('flush');
        $this->fileProcessor->expects(self::never())->method('extractText');
        $this->rateLimitService->expects(self::never())->method('checkLimit');

        $result = $this->makeService()->processFile($file, $this->makeUser());

        $this->assertTrue($result['success']);
        $this->assertSame('extracted', $result['status']);
        $this->assertTrue($result['extraction_skipped']);
    }

    public function testProcessFileLeavesAlreadyExtractedJarUnchanged(): void
    {
        $file = $this->createMock(File::class);
        $file->method('getStatus')->willReturn('extracted');
        $file->method('getFileType')->willReturn('jar');
        $file->method('getFilePath')->willReturn('user/1/mod.jar');
        $file->expects(self::never())->method('setStatus');
        $this->em->expects(self::never())->method('flush');
        $this->fileProcessor->expects(self::never())->method('extractText');

        $result = $this->makeService()->processFile($file, $this->makeUser());

        $this->assertTrue($result['success']);
        $this->assertSame('extracted', $result['status']);
        $this->assertTrue($result['extraction_skipped']);
    }
}
