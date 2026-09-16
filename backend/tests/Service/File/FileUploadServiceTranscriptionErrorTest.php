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
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Verifies that FileUploadService marks files whose extract produced no text
 * as status='error' rather than the previous misleading 'vectorized'
 * (async path). Blank PDFs and silent media share that contract.
 *
 * Covers PR #1095 QA review Finding 3.
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class FileUploadServiceTranscriptionErrorTest extends TestCase
{
    private FileProcessor&MockObject $fileProcessor;
    private EntityManagerInterface&MockObject $em;
    private RateLimitService&MockObject $rateLimitService;
    private FileStorageService&MockObject $storageService;

    protected function setUp(): void
    {
        $this->fileProcessor = $this->createMock(FileProcessor::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->rateLimitService = $this->createMock(RateLimitService::class);
        $this->storageService = $this->createMock(FileStorageService::class);

        $this->rateLimitService
            ->method('checkLimit')
            ->willReturn(['allowed' => true, 'used' => 0, 'limit' => 100]);
    }

    private function makeService(): FileUploadService
    {
        return new FileUploadService(
            $this->storageService,
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

    private function makeFileMock(string $type, string $extractedText = ''): File&MockObject
    {
        $file = $this->createMock(File::class);
        $file->method('getStatus')->willReturn('uploaded');
        $file->method('getFileType')->willReturn($type);
        $file->method('getFilePath')->willReturn('user/1/test.'.$type);
        $file->method('getFileText')->willReturn($extractedText);
        $file->method('getId')->willReturn(42);
        $file->method('getGroupKey')->willReturn(null);
        $file->method('getFileName')->willReturn('test.'.$type);

        return $file;
    }

    private function makeUser(): User&MockObject
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);

        return $user;
    }

    public function testProcessFileReturnsErrorForEmptyMp4Transcript(): void
    {
        $this->fileProcessor
            ->method('extractText')
            ->willReturn(['', ['strategy' => 'audio_api_failed']]);

        $result = $this->makeService()->processFile($this->makeFileMock('mp4'), $this->makeUser());

        $this->assertFalse($result['success']);
        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('Transcription', $result['error']);
    }

    public function testProcessFileReturnsErrorForEmptyMp3Transcript(): void
    {
        $this->fileProcessor
            ->method('extractText')
            ->willReturn(['', ['strategy' => 'audio_api_failed']]);

        $result = $this->makeService()->processFile($this->makeFileMock('mp3', ''), $this->makeUser());

        $this->assertFalse($result['success']);
        $this->assertSame('error', $result['status']);
    }

    public function testProcessFileReturnsErrorForEmptyMovTranscript(): void
    {
        $this->fileProcessor
            ->method('extractText')
            ->willReturn(['', ['strategy' => 'audio_api_failed']]);

        $result = $this->makeService()->processFile($this->makeFileMock('mov', ''), $this->makeUser());

        $this->assertFalse($result['success']);
        $this->assertSame('error', $result['status']);
    }

    public function testProcessFileReExtractsWhenPreviousExtractWasEmpty(): void
    {
        $this->fileProcessor
            ->expects(self::once())
            ->method('extractText')
            ->willReturn(['', ['strategy' => 'rasterize_vision']]);

        $file = $this->createMock(File::class);
        $file->method('getStatus')->willReturn('extracted');
        $file->method('getFileType')->willReturn('pdf');
        $file->method('getFilePath')->willReturn('user/1/scan.pdf');
        $file->method('getFileText')->willReturn('');
        $file->method('getId')->willReturn(90);
        $file->method('getGroupKey')->willReturn(null);
        $file->method('getFileName')->willReturn('scan.pdf');

        $result = $this->makeService()->processFile($file, $this->makeUser());

        $this->assertFalse($result['success']);
        $this->assertSame('error', $result['status']);
    }

    public function testProcessFileErrorsForEmptyPdf(): void
    {
        $this->fileProcessor
            ->method('extractText')
            ->willReturn(['', ['strategy' => 'tika_failed']]);

        // A scanned or unreadable PDF that yielded no text (after Tika + vision)
        // must not pretend to be vectorized — that showed as "Ready for chat".
        $result = $this->makeService()->processFile($this->makeFileMock('pdf', ''), $this->makeUser());

        $this->assertFalse($result['success']);
        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('Unable to extract information', $result['error']);
    }

    /**
     * Synchronous upload (process_level=vectorize): an empty extract must NOT
     * become a batch error — the stored row and its id are returned in `files`
     * with status=error so the client (Desktop) can keep the local source and
     * show the failure on the file. The entity itself is left failed, never
     * vectorized.
     */
    public function testUploadBatchKeepsEmptyExtractInFilesWithErrorStatus(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'scan_');
        self::assertIsString($tmp);
        file_put_contents($tmp, '%PDF-1.4 scan');
        $uploaded = new UploadedFile($tmp, 'Gutschein.pdf', 'application/pdf', null, true);

        $this->storageService
            ->method('storeUploadedFile')
            ->willReturn([
                'success' => true,
                'path' => '01/000/00001/2026/09/Gutschein_1.pdf',
                'extension' => 'pdf',
                'size' => 13,
                'mime' => 'application/pdf',
            ]);

        $persisted = null;
        $this->em
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$persisted): void {
                $persisted = $entity;
            });

        $this->fileProcessor
            ->expects(self::once())
            ->method('extractText')
            ->willReturn(['', ['strategy' => 'vision_failed']]);

        try {
            $batch = $this->makeService()->uploadBatch([$uploaded], $this->makeUser(), 'DESKTOP:p1', 'vectorize');
        } finally {
            @unlink($tmp);
        }

        $this->assertTrue($batch['success']);
        $this->assertSame([], $batch['errors']);
        $this->assertCount(1, $batch['files']);

        $row = $batch['files'][0];
        $this->assertArrayHasKey('id', $row);
        $this->assertSame('error', $row['status']);
        $this->assertSame(0, $row['extracted_text_length']);
        $this->assertStringContainsString('Unable to extract information', $row['error']);

        $this->assertInstanceOf(File::class, $persisted);
        $this->assertSame('error', $persisted->getStatus());
        $this->assertSame(File::VECTOR_STATE_FAILED, $persisted->getVectorState());
    }
}
