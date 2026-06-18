<?php

declare(strict_types=1);

namespace App\Tests\Service\File;

use App\Entity\File;
use App\Entity\User;
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
 * Verifies that FileUploadService marks audio/video files whose transcription
 * produced no text as status='error' rather than the previous misleading
 * 'vectorized' (async path).  Non-media files (e.g. blank PDFs) must continue
 * to reach 'vectorized' so existing behaviour is not regressed.
 *
 * Covers PR #1095 QA review Finding 3.
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class FileUploadServiceTranscriptionErrorTest extends TestCase
{
    private FileProcessor&MockObject $fileProcessor;
    private EntityManagerInterface&MockObject $em;
    private RateLimitService&MockObject $rateLimitService;

    protected function setUp(): void
    {
        $this->fileProcessor = $this->createMock(FileProcessor::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->rateLimitService = $this->createMock(RateLimitService::class);

        $this->rateLimitService
            ->method('checkLimit')
            ->willReturn(['allowed' => true, 'used' => 0, 'limit' => 100]);
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

    public function testProcessFileDoesNotErrorForEmptyPdf(): void
    {
        $this->fileProcessor
            ->method('extractText')
            ->willReturn(['', ['strategy' => 'tika_failed']]);

        // A blank or unreadable PDF is not a transcription failure — the file
        // reaches vectorized with 0 chunks (no content to index).
        $result = $this->makeService()->processFile($this->makeFileMock('pdf', ''), $this->makeUser());

        $this->assertTrue($result['success']);
        $this->assertSame('vectorized', $result['status']);
    }
}
